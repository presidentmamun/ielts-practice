<?php
/**
 * Plugin Name: IELTS Desk Accounts
 * Description: Free sign-up and login for the IELTS Practice Desk app. Learners get their own WordPress account (role "IELTS Learner"), the natural listening voices, and their progress saved to their account. Settings → IELTS Desk.
 * Version: 1.0.1
 * Author: Iwate BD
 * Requires at least: 5.6
 * Requires PHP: 7.4
 *
 * The app talks to these addresses (all POST, JSON body, no cookies):
 *   /wp-json/ielts/v1/signup    {name, email, password}  -> session
 *   /wp-json/ielts/v1/login     {email, password}        -> session
 *   /wp-json/ielts/v1/me        {token}                  -> session without a new token
 *   /wp-json/ielts/v1/progress  {token, progress}        -> {ok}
 *   /wp-json/ielts/v1/logout    {token}                  -> {ok}
 * session = {token, name, email, key, progress}; "key" unlocks the natural-voice recordings in the app.
 *
 * Only accounts with the "IELTS Learner" role can log in here, so this never becomes a second,
 * weaker door for editors or administrators of the site.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class IELTS_Desk_Accounts {

	const NS           = 'ielts/v1';
	const ROLE         = 'ielts_learner';
	const OPT          = 'ielts_desk_settings';
	const TOKEN_DAYS   = 180;      // a login on one device lasts this long
	const MAX_TOKENS   = 5;        // devices per account
	const MAX_PROGRESS = 300000;   // bytes of saved progress per account

	public static function init() {
		register_activation_hook( __FILE__, array( __CLASS__, 'add_role' ) );
		add_action( 'init', array( __CLASS__, 'add_role' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_ielts_desk_save', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_ielts_desk_approve', array( __CLASS__, 'approve' ) );
		add_action( 'init', array( __CLASS__, 'register_setting' ) );
	}

	/** 1.0.1: lets an administrator change the settings through /wp/v2/settings (Application Password), not only the admin page. */
	public static function register_setting() {
		register_setting( 'options', self::OPT, array(
			'type'              => 'object',
			'default'           => array( 'key' => '', 'approve' => 0 ),
			'sanitize_callback' => function ( $v ) {
				$v = (array) $v;
				return array(
					'key'     => isset( $v['key'] ) ? trim( sanitize_text_field( (string) $v['key'] ) ) : '',
					'approve' => empty( $v['approve'] ) ? 0 : 1,
				);
			},
			'show_in_rest'      => array( 'schema' => array( 'type' => 'object', 'properties' => array(
				'key'     => array( 'type' => 'string' ),
				'approve' => array( 'type' => 'integer' ),
			) ) ),
		) );
	}

	public static function add_role() {
		if ( ! get_role( self::ROLE ) ) {
			add_role( self::ROLE, 'IELTS Learner', array( 'read' => true ) );
		}
	}

	private static function settings() {
		return wp_parse_args( (array) get_option( self::OPT, array() ), array( 'key' => '', 'approve' => 0 ) );
	}

	/* ---------- REST ---------- */

	public static function routes() {
		foreach ( array( 'signup', 'login', 'me', 'progress', 'logout' ) as $r ) {
			register_rest_route( self::NS, '/' . $r, array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'r_' . $r ),
				'permission_callback' => '__return_true',
			) );
		}
	}

	private static function err( $code, $message, $status ) {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/** At most $max tries per visitor address in $seconds. */
	private static function throttle( $bucket, $max, $seconds ) {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key = 'ielts_rl_' . md5( $bucket . '|' . $ip );
		$n   = (int) get_transient( $key );
		if ( $n >= $max ) {
			return self::err( 'ielts_slow', 'Too many attempts. Please wait a few minutes and try again.', 429 );
		}
		set_transient( $key, $n + 1, $seconds );
		return true;
	}

	private static function body( $request ) {
		$p = $request->get_json_params();
		return is_array( $p ) ? $p : array();
	}

	private static function str( $p, $k ) {
		return isset( $p[ $k ] ) && is_string( $p[ $k ] ) ? $p[ $k ] : '';
	}

	private static function is_learner( $user ) {
		return $user instanceof WP_User && in_array( self::ROLE, (array) $user->roles, true );
	}

	private static function approved( $user ) {
		$s = self::settings();
		return ! $s['approve'] || '1' === (string) get_user_meta( $user->ID, 'ielts_approved', true );
	}

	private static function progress_of( $user ) {
		$raw = get_user_meta( $user->ID, 'ielts_progress', true );
		$p   = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;
		return is_array( $p ) ? $p : null;
	}

	/** What the app needs after a successful login; a new device token only when $new_token. */
	private static function session( $user, $new_token ) {
		$s   = self::settings();
		$out = array(
			'name'     => $user->display_name,
			'email'    => $user->user_email,
			'key'      => (string) $s['key'],
			'progress' => self::progress_of( $user ),
		);
		if ( $new_token ) {
			$token  = bin2hex( random_bytes( 32 ) );
			$tokens = (array) get_user_meta( $user->ID, 'ielts_tokens', true );
			$now    = time();
			$tokens = array_filter( $tokens, function ( $exp ) use ( $now ) {
				return is_int( $exp ) && $exp > $now;
			} );
			asort( $tokens );
			$tokens = array_slice( $tokens, -( self::MAX_TOKENS - 1 ), null, true );   // the oldest device is signed out
			$tokens[ hash( 'sha256', $token ) ] = $now + self::TOKEN_DAYS * DAY_IN_SECONDS;
			update_user_meta( $user->ID, 'ielts_tokens', $tokens );
			$out['token'] = $user->ID . '.' . $token;
		}
		nocache_headers();
		return rest_ensure_response( $out );
	}

	/** The learner a token belongs to, or an error. Only a hash of the token is stored. */
	private static function auth( $p ) {
		$bad   = self::err( 'ielts_token', 'Please log in again.', 401 );
		$parts = explode( '.', self::str( $p, 'token' ), 2 );
		if ( 2 !== count( $parts ) || ! ctype_digit( $parts[0] ) || ! ctype_xdigit( $parts[1] ) ) {
			return $bad;
		}
		$user = get_user_by( 'id', (int) $parts[0] );
		if ( ! self::is_learner( $user ) || ! self::approved( $user ) ) {
			return $bad;
		}
		$tokens = (array) get_user_meta( $user->ID, 'ielts_tokens', true );
		$hash   = hash( 'sha256', $parts[1] );
		if ( ! isset( $tokens[ $hash ] ) || (int) $tokens[ $hash ] < time() ) {
			return $bad;
		}
		return $user;
	}

	public static function r_signup( $request ) {
		$t = self::throttle( 'signup', 5, HOUR_IN_SECONDS );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		$p     = self::body( $request );
		$name  = trim( sanitize_text_field( self::str( $p, 'name' ) ) );
		$email = sanitize_email( self::str( $p, 'email' ) );
		$pass  = self::str( $p, 'password' );
		if ( '' !== self::str( $p, 'website' ) ) {   // hidden field that only bots fill in
			return self::err( 'ielts_signup', 'Sign-up failed. Please try again.', 400 );
		}
		if ( '' === $name || mb_strlen( $name ) > 60 ) {
			return self::err( 'ielts_name', 'Please enter your name.', 400 );
		}
		if ( ! is_email( $email ) ) {
			return self::err( 'ielts_email', 'Please enter a valid email address.', 400 );
		}
		if ( strlen( $pass ) < 8 || strlen( $pass ) > 200 ) {
			return self::err( 'ielts_password', 'Choose a password with at least 8 characters.', 400 );
		}
		if ( email_exists( $email ) ) {
			return self::err( 'ielts_exists', 'There is already an account with this email. Log in instead.', 409 );
		}
		$base = sanitize_user( strstr( $email, '@', true ), true );
		$base = '' !== $base ? substr( $base, 0, 40 ) : 'learner';
		$login = $base;
		while ( username_exists( $login ) ) {
			$login = $base . wp_rand( 100, 99999 );
		}
		self::add_role();
		// WordPress keeps passwords "slashed" everywhere (login form, reset form), so do the same here.
		$id = wp_insert_user( array(
			'user_login'   => $login,
			'user_email'   => $email,
			'user_pass'    => wp_slash( $pass ),
			'display_name' => $name,
			'first_name'   => $name,
			'role'         => self::ROLE,
		) );
		if ( is_wp_error( $id ) ) {
			return self::err( 'ielts_signup', 'Sign-up failed. Please try again.', 500 );
		}
		$s = self::settings();
		update_user_meta( $id, 'ielts_approved', $s['approve'] ? '0' : '1' );
		if ( $s['approve'] ) {
			nocache_headers();
			return rest_ensure_response( array( 'pending' => true ) );
		}
		return self::session( get_user_by( 'id', $id ), true );
	}

	public static function r_login( $request ) {
		$t = self::throttle( 'login', 10, 15 * MINUTE_IN_SECONDS );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		$p    = self::body( $request );
		$user = get_user_by( 'email', sanitize_email( self::str( $p, 'email' ) ) );
		$pass = self::str( $p, 'password' );
		if ( ! self::is_learner( $user ) || '' === $pass || ! wp_check_password( wp_slash( $pass ), $user->user_pass, $user->ID ) ) {
			return self::err( 'ielts_login', 'Wrong email or password.', 401 );
		}
		if ( ! self::approved( $user ) ) {
			return self::err( 'ielts_pending', 'Your account is waiting for approval.', 403 );
		}
		return self::session( $user, true );
	}

	public static function r_me( $request ) {
		$user = self::auth( self::body( $request ) );
		return is_wp_error( $user ) ? $user : self::session( $user, false );
	}

	public static function r_progress( $request ) {
		$p    = self::body( $request );
		$user = self::auth( $p );
		if ( is_wp_error( $user ) ) {
			return $user;
		}
		if ( ! isset( $p['progress'] ) || ! is_array( $p['progress'] ) ) {
			return self::err( 'ielts_progress', 'Nothing to save.', 400 );
		}
		$json = wp_json_encode( $p['progress'] );
		if ( false === $json || strlen( $json ) > self::MAX_PROGRESS ) {
			return self::err( 'ielts_progress', 'Too much data to save.', 413 );
		}
		update_user_meta( $user->ID, 'ielts_progress', wp_slash( $json ) );
		nocache_headers();
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function r_logout( $request ) {
		$p    = self::body( $request );
		$user = self::auth( $p );
		if ( ! is_wp_error( $user ) ) {
			$parts  = explode( '.', self::str( $p, 'token' ), 2 );
			$tokens = (array) get_user_meta( $user->ID, 'ielts_tokens', true );
			unset( $tokens[ hash( 'sha256', $parts[1] ) ] );
			update_user_meta( $user->ID, 'ielts_tokens', $tokens );
		}
		nocache_headers();
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/* ---------- Settings → IELTS Desk ---------- */

	public static function menu() {
		add_options_page( 'IELTS Desk', 'IELTS Desk', 'manage_options', 'ielts-desk', array( __CLASS__, 'page' ) );
	}

	public static function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'ielts_desk_save' );
		update_option( self::OPT, array(
			'key'     => isset( $_POST['key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['key'] ) ) ) : '',
			'approve' => empty( $_POST['approve'] ) ? 0 : 1,
		), false );
		wp_safe_redirect( admin_url( 'options-general.php?page=ielts-desk&saved=1' ) );
		exit;
	}

	public static function approve() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		$id = isset( $_GET['user'] ) ? (int) $_GET['user'] : 0;
		check_admin_referer( 'ielts_desk_approve_' . $id );
		if ( self::is_learner( get_user_by( 'id', $id ) ) ) {
			update_user_meta( $id, 'ielts_approved', '1' );
		}
		wp_safe_redirect( admin_url( 'options-general.php?page=ielts-desk' ) );
		exit;
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s       = self::settings();
		$total   = count( get_users( array( 'role' => self::ROLE, 'fields' => 'ID' ) ) );
		$pending = $s['approve'] ? get_users( array( 'role' => self::ROLE, 'meta_key' => 'ielts_approved', 'meta_value' => '0', 'number' => 100 ) ) : array();
		?>
		<div class="wrap">
			<h1>IELTS Desk</h1>
			<?php if ( isset( $_GET['saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Saved.</p></div><?php endif; ?>
			<p><strong><?php echo (int) $total; ?></strong> learner account(s).
				<a href="<?php echo esc_url( admin_url( 'users.php?role=' . self::ROLE ) ); ?>">See them in Users</a> (delete a user there to remove their access).</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ielts_desk_save">
				<?php wp_nonce_field( 'ielts_desk_save' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ielts-key">Natural voices key</label></th>
						<td><input type="text" id="ielts-key" name="key" class="regular-text" value="<?php echo esc_attr( $s['key'] ); ?>" autocomplete="off">
							<p class="description">The text inside <code>dev/premium-key.txt</code> of the app. Logged-in learners receive it so the app can play the natural-voice recordings. Leave empty to give accounts progress saving only.</p></td>
					</tr>
					<tr>
						<th scope="row">New accounts</th>
						<td><label><input type="checkbox" name="approve" value="1" <?php checked( $s['approve'], 1 ); ?>> I approve each new account before it can log in</label></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
			<?php if ( $pending ) : ?>
				<h2>Waiting for approval</h2>
				<table class="widefat striped" style="max-width:720px"><tbody>
				<?php foreach ( $pending as $u ) : ?>
					<tr><td><?php echo esc_html( $u->display_name ); ?></td><td><?php echo esc_html( $u->user_email ); ?></td>
						<td><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ielts_desk_approve&user=' . $u->ID ), 'ielts_desk_approve_' . $u->ID ) ); ?>">Approve</a></td></tr>
				<?php endforeach; ?>
				</tbody></table>
			<?php endif; ?>
		</div>
		<?php
	}
}

IELTS_Desk_Accounts::init();
