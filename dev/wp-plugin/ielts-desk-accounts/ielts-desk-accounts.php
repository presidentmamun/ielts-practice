<?php
/**
 * Plugin Name: IELTS Desk Accounts
 * Description: Free sign-up and login for the IELTS Practice Desk app (ielts.iwate-bd.org). Learners get their own WordPress account (role "IELTS Learner"), confirm their email with a 6-digit code or a link, and keep their progress. Manage everyone under IELTS Desk in the admin menu: block, unblock, delete, or add a learner yourself.
 * Version: 1.1.0
 * Author: Iwate BD
 * Requires at least: 5.6
 * Requires PHP: 7.4
 *
 * The app talks to these addresses (POST, JSON body, no cookies):
 *   /wp-json/ielts/v1/signup        {name, email, password}   -> {verify: true, email}   (code + link emailed)
 *   /wp-json/ielts/v1/verify        {email, code}             -> session
 *   /wp-json/ielts/v1/resend        {email}                   -> {ok}                    (new code + link)
 *   /wp-json/ielts/v1/login         {email, password}         -> session  | 403 ielts_unverified / ielts_blocked / ielts_pending
 *   /wp-json/ielts/v1/forgot        {email}                   -> {ok}                    (reset code emailed)
 *   /wp-json/ielts/v1/reset         {email, code, password}   -> session
 *   /wp-json/ielts/v1/me            {token}                   -> session without a new token
 *   /wp-json/ielts/v1/progress      {token, progress}         -> {ok}
 *   /wp-json/ielts/v1/logout        {token}                   -> {ok}
 *   GET /wp-json/ielts/v1/confirm?u=ID&t=TOKEN  (the link in the email) -> redirect to the app with ?confirmed=1
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
	const CODE_MIN     = 30;       // a code / link works this many minutes
	const CODE_TRIES   = 5;        // wrong codes before a new one is needed
	const APP_URL      = 'https://ielts.iwate-bd.org/';
	const CONTACT      = 'iwate.bdex@gmail.com';

	public static function init() {
		register_activation_hook( __FILE__, array( __CLASS__, 'add_role' ) );
		add_action( 'init', array( __CLASS__, 'add_role' ) );
		add_action( 'init', array( __CLASS__, 'register_setting' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		foreach ( array( 'save', 'act', 'add' ) as $a ) {
			add_action( 'admin_post_ielts_desk_' . $a, array( __CLASS__, 'admin_' . $a ) );
		}
	}

	/** Lets an administrator change the settings through /wp/v2/settings (Application Password), not only the admin page. */
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

	/* ---------- account state ---------- */

	private static function is_learner( $user ) {
		return $user instanceof WP_User && in_array( self::ROLE, (array) $user->roles, true );
	}

	private static function approved( $user ) {
		$s = self::settings();
		return ! $s['approve'] || '1' === (string) get_user_meta( $user->ID, 'ielts_approved', true );
	}

	/** Accounts made before 1.1.0 have no flag and count as confirmed. */
	private static function verified( $user ) {
		return '0' !== (string) get_user_meta( $user->ID, 'ielts_verified', true );
	}

	private static function blocked( $user ) {
		return '1' === (string) get_user_meta( $user->ID, 'ielts_blocked', true );
	}

	private static function touch( $user ) {
		$last = (int) get_user_meta( $user->ID, 'ielts_last', true );
		if ( time() - $last > 600 ) {
			update_user_meta( $user->ID, 'ielts_last', time() );
		}
	}

	private static function blocked_err() {
		return self::err( 'ielts_blocked', 'Your account has been suspended. Contact ' . self::CONTACT, 403 );
	}

	/* ---------- REST ---------- */

	public static function routes() {
		foreach ( array( 'signup', 'verify', 'resend', 'login', 'forgot', 'reset', 'me', 'progress', 'logout' ) as $r ) {
			register_rest_route( self::NS, '/' . $r, array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'r_' . $r ),
				'permission_callback' => '__return_true',
			) );
		}
		register_rest_route( self::NS, '/confirm', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'r_confirm' ),
			'permission_callback' => '__return_true',
		) );
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

	private static function learner_by_email( $p ) {
		$user = get_user_by( 'email', sanitize_email( self::str( $p, 'email' ) ) );
		return self::is_learner( $user ) ? $user : null;
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
		self::touch( $user );
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
		if ( ! self::is_learner( $user ) || ! self::approved( $user ) || ! self::verified( $user ) ) {
			return $bad;
		}
		if ( self::blocked( $user ) ) {
			return self::blocked_err();
		}
		$tokens = (array) get_user_meta( $user->ID, 'ielts_tokens', true );
		$hash   = hash( 'sha256', $parts[1] );
		if ( ! isset( $tokens[ $hash ] ) || (int) $tokens[ $hash ] < time() ) {
			return $bad;
		}
		return $user;
	}

	/* ---------- codes by email ---------- */

	/** A new 6-digit code (and, for confirming, a link) for $purpose 'verify' or 'reset'; emails it. */
	private static function send_code( $user, $purpose ) {
		$code = (string) wp_rand( 100000, 999999 );
		$link = bin2hex( random_bytes( 20 ) );
		update_user_meta( $user->ID, 'ielts_code_' . $purpose, array(
			'h'     => wp_hash_password( $code ),
			'link'  => 'verify' === $purpose ? hash( 'sha256', $link ) : '',
			'exp'   => time() + self::CODE_MIN * MINUTE_IN_SECONDS,
			'tries' => 0,
		) );
		$name = $user->display_name ? $user->display_name : 'there';
		if ( 'verify' === $purpose ) {
			$url     = add_query_arg( array( 'u' => $user->ID, 't' => $link ), rest_url( self::NS . '/confirm' ) );
			$subject = 'Your IELTS Practice Desk code: ' . $code;
			$text    = "Hello {$name},\n\nThank you for joining IELTS Practice Desk.\n\nYour confirmation code is:\n\n    {$code}\n\n"
				. "Type this code in the app, or tap this link to confirm your email:\n{$url}\n\n"
				. 'The code and the link work for ' . self::CODE_MIN . " minutes.\nIf you did not sign up, you can ignore this email.\n\n"
				. "IELTS Practice Desk\n" . self::APP_URL;
		} else {
			$subject = 'Your IELTS Practice Desk password code: ' . $code;
			$text    = "Hello {$name},\n\nSomeone (hopefully you) asked to change the password of your IELTS Practice Desk account.\n\n"
				. "Your code is:\n\n    {$code}\n\nType it in the app and choose a new password. It works for " . self::CODE_MIN . " minutes.\n"
				. "If you did not ask for this, ignore this email; your password stays the same.\n\nIELTS Practice Desk\n" . self::APP_URL;
		}
		return wp_mail( $user->user_email, $subject, $text, array( 'Reply-To: IELTS Practice Desk <' . self::CONTACT . '>' ) );
	}

	/** true, or an error saying why the code is not accepted. Used codes are removed. */
	private static function check_code( $user, $purpose, $code ) {
		$k = 'ielts_code_' . $purpose;
		$c = get_user_meta( $user->ID, $k, true );
		$expired = self::err( 'ielts_code', 'This code has expired. Ask for a new one.', 400 );
		if ( ! is_array( $c ) || empty( $c['h'] ) || (int) $c['exp'] < time() ) {
			return $expired;
		}
		if ( (int) $c['tries'] >= self::CODE_TRIES ) {
			return self::err( 'ielts_code', 'Too many wrong codes. Ask for a new one.', 400 );
		}
		$code = preg_replace( '/\D/', '', (string) $code );
		if ( 6 !== strlen( $code ) || ! wp_check_password( $code, $c['h'] ) ) {
			$c['tries'] = (int) $c['tries'] + 1;
			update_user_meta( $user->ID, $k, $c );
			return self::err( 'ielts_code', 'That code is not right. Check the newest email and try again.', 400 );
		}
		delete_user_meta( $user->ID, $k );
		return true;
	}

	/* ---------- routes ---------- */

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
		$old = get_user_by( 'email', $email );
		if ( $old ) {
			if ( self::is_learner( $old ) && ! self::verified( $old ) && ! self::blocked( $old ) ) {
				// signed up before but never confirmed: take the new name and password, send a fresh code
				wp_update_user( array( 'ID' => $old->ID, 'user_pass' => wp_slash( $pass ), 'display_name' => $name, 'first_name' => $name ) );
				self::send_code( get_user_by( 'id', $old->ID ), 'verify' );
				nocache_headers();
				return rest_ensure_response( array( 'verify' => true, 'email' => $email ) );
			}
			return self::err( 'ielts_exists', 'There is already an account with this email. Log in instead.', 409 );
		}
		$id = self::create( $name, $email, $pass, false );
		if ( is_wp_error( $id ) ) {
			return self::err( 'ielts_signup', 'Sign-up failed. Please try again.', 500 );
		}
		if ( ! self::send_code( get_user_by( 'id', $id ), 'verify' ) ) {
			return self::err( 'ielts_mail', 'Your account was made, but the email could not be sent. Choose "Send the code again" in a minute.', 500 );
		}
		nocache_headers();
		return rest_ensure_response( array( 'verify' => true, 'email' => $email ) );
	}

	/** A new learner. $confirmed = made by the administrator (no email check, already approved). */
	private static function create( $name, $email, $pass, $confirmed ) {
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
			return $id;
		}
		$s = self::settings();
		update_user_meta( $id, 'ielts_verified', $confirmed ? '1' : '0' );
		update_user_meta( $id, 'ielts_approved', ( $confirmed || ! $s['approve'] ) ? '1' : '0' );
		update_user_meta( $id, 'ielts_by_admin', $confirmed ? '1' : '0' );
		return $id;
	}

	/** After the email is confirmed: a session, or "waiting for approval". */
	private static function after_confirm( $user ) {
		if ( ! self::approved( $user ) ) {
			nocache_headers();
			return rest_ensure_response( array( 'pending' => true ) );
		}
		return self::session( $user, true );
	}

	public static function r_verify( $request ) {
		$t = self::throttle( 'verify', 20, 15 * MINUTE_IN_SECONDS );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		$p    = self::body( $request );
		$user = self::learner_by_email( $p );
		if ( ! $user ) {
			return self::err( 'ielts_code', 'That code is not right. Check the newest email and try again.', 400 );
		}
		if ( self::blocked( $user ) ) {
			return self::blocked_err();
		}
		if ( self::verified( $user ) ) {   // already confirmed (for example with the link): the password is needed now
			return self::err( 'ielts_confirmed', 'Your email is already confirmed. Log in with your password.', 409 );
		}
		$ok = self::check_code( $user, 'verify', self::str( $p, 'code' ) );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		update_user_meta( $user->ID, 'ielts_verified', '1' );
		return self::after_confirm( $user );
	}

	/** The link in the email. Always ends on the app, with ?confirmed=1 or ?confirmed=0. */
	public static function r_confirm( $request ) {
		$id   = (int) $request->get_param( 'u' );
		$tok  = (string) $request->get_param( 't' );
		$user = get_user_by( 'id', $id );
		$ok   = 0;
		if ( self::is_learner( $user ) ) {
			if ( self::verified( $user ) ) {
				$ok = 1;
			} else {
				$c = get_user_meta( $user->ID, 'ielts_code_verify', true );
				if ( is_array( $c ) && ! empty( $c['link'] ) && (int) $c['exp'] >= time() && ctype_xdigit( $tok )
					&& hash_equals( $c['link'], hash( 'sha256', $tok ) ) ) {
					update_user_meta( $user->ID, 'ielts_verified', '1' );
					delete_user_meta( $user->ID, 'ielts_code_verify' );
					$ok = 1;
				}
			}
		}
		nocache_headers();
		wp_redirect( add_query_arg( 'confirmed', $ok, self::APP_URL ), 302, 'IELTS Desk' );
		exit;
	}

	public static function r_resend( $request ) {
		$t = self::throttle( 'resend', 5, HOUR_IN_SECONDS );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		$user = self::learner_by_email( self::body( $request ) );
		if ( $user && ! self::verified( $user ) && ! self::blocked( $user ) ) {
			self::send_code( $user, 'verify' );
		}
		nocache_headers();
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function r_login( $request ) {
		$t = self::throttle( 'login', 10, 15 * MINUTE_IN_SECONDS );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		$p    = self::body( $request );
		$user = self::learner_by_email( $p );
		$pass = self::str( $p, 'password' );
		if ( ! $user || '' === $pass || ! wp_check_password( wp_slash( $pass ), $user->user_pass, $user->ID ) ) {
			return self::err( 'ielts_login', 'Wrong email or password.', 401 );
		}
		if ( self::blocked( $user ) ) {
			return self::blocked_err();
		}
		if ( ! self::verified( $user ) ) {
			return self::err( 'ielts_unverified', 'Please confirm your email first.', 403 );
		}
		if ( ! self::approved( $user ) ) {
			return self::err( 'ielts_pending', 'Your account is waiting for approval.', 403 );
		}
		return self::session( $user, true );
	}

	public static function r_forgot( $request ) {
		$t = self::throttle( 'forgot', 5, HOUR_IN_SECONDS );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		$user = self::learner_by_email( self::body( $request ) );
		if ( $user && ! self::blocked( $user ) ) {
			self::send_code( $user, 'reset' );
		}
		nocache_headers();
		return rest_ensure_response( array( 'ok' => true ) );   // the same answer whether the account exists or not
	}

	public static function r_reset( $request ) {
		$t = self::throttle( 'reset', 20, 15 * MINUTE_IN_SECONDS );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		$p    = self::body( $request );
		$pass = self::str( $p, 'password' );
		if ( strlen( $pass ) < 8 || strlen( $pass ) > 200 ) {
			return self::err( 'ielts_password', 'Choose a password with at least 8 characters.', 400 );
		}
		$user = self::learner_by_email( $p );
		if ( ! $user ) {
			return self::err( 'ielts_code', 'That code is not right. Check the newest email and try again.', 400 );
		}
		if ( self::blocked( $user ) ) {
			return self::blocked_err();
		}
		$ok = self::check_code( $user, 'reset', self::str( $p, 'code' ) );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		wp_set_password( wp_slash( $pass ), $user->ID );   // also signs out every other device
		delete_user_meta( $user->ID, 'ielts_tokens' );
		update_user_meta( $user->ID, 'ielts_verified', '1' );   // the code proved the email
		return self::after_confirm( get_user_by( 'id', $user->ID ) );
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
		self::touch( $user );
		nocache_headers();
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function r_logout( $request ) {
		$p     = self::body( $request );
		$parts = explode( '.', self::str( $p, 'token' ), 2 );
		$user  = 2 === count( $parts ) && ctype_digit( $parts[0] ) ? get_user_by( 'id', (int) $parts[0] ) : null;
		if ( self::is_learner( $user ) ) {
			$tokens = (array) get_user_meta( $user->ID, 'ielts_tokens', true );
			unset( $tokens[ hash( 'sha256', (string) $parts[1] ) ] );
			update_user_meta( $user->ID, 'ielts_tokens', $tokens );
		}
		nocache_headers();
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/* ---------- admin: IELTS Desk menu ---------- */

	public static function menu() {
		add_menu_page( 'IELTS Desk', 'IELTS Desk', 'manage_options', 'ielts-desk', array( __CLASS__, 'page' ), 'dashicons-welcome-learn-more', 58 );
	}

	private static function back( $args = array() ) {
		$keep = array();
		foreach ( array( 's', 'f', 'paged' ) as $k ) {
			if ( isset( $_REQUEST[ $k ] ) && '' !== $_REQUEST[ $k ] ) {
				$keep[ $k ] = sanitize_text_field( wp_unslash( $_REQUEST[ $k ] ) );
			}
		}
		wp_safe_redirect( add_query_arg( array_merge( $keep, $args ), admin_url( 'admin.php?page=ielts-desk' ) ) );
		exit;
	}

	public static function admin_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'ielts_desk_save' );
		update_option( self::OPT, array(
			'key'     => isset( $_POST['key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['key'] ) ) ) : '',
			'approve' => empty( $_POST['approve'] ) ? 0 : 1,
		), false );
		self::back( array( 'msg' => 'saved' ) );
	}

	/** One action on one learner: block, unblock, approve, verify, delete. */
	public static function admin_act() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		$id = isset( $_REQUEST['user'] ) ? (int) $_REQUEST['user'] : 0;
		$do = isset( $_REQUEST['do'] ) ? sanitize_key( $_REQUEST['do'] ) : '';
		check_admin_referer( 'ielts_desk_act_' . $do . '_' . $id );
		$user = get_user_by( 'id', $id );
		if ( ! self::is_learner( $user ) ) {
			self::back( array( 'msg' => 'notlearner' ) );
		}
		switch ( $do ) {
			case 'block':
				update_user_meta( $id, 'ielts_blocked', '1' );
				delete_user_meta( $id, 'ielts_tokens' );   // signed out everywhere at once
				break;
			case 'unblock':
				delete_user_meta( $id, 'ielts_blocked' );
				break;
			case 'approve':
				update_user_meta( $id, 'ielts_approved', '1' );
				break;
			case 'verify':
				update_user_meta( $id, 'ielts_verified', '1' );
				delete_user_meta( $id, 'ielts_code_verify' );
				break;
			case 'delete':
				require_once ABSPATH . 'wp-admin/includes/user.php';
				wp_delete_user( $id );
				break;
			default:
				self::back();
		}
		self::back( array( 'msg' => $do ) );
	}

	/** The administrator adds a learner with a password; the account works straight away. */
	public static function admin_add() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'ielts_desk_add' );
		$name  = isset( $_POST['name'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['name'] ) ) ) : '';
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$pass  = isset( $_POST['pass'] ) ? (string) wp_unslash( $_POST['pass'] ) : '';
		if ( '' === $name || ! is_email( $email ) || strlen( $pass ) < 8 ) {
			self::back( array( 'msg' => 'addbad' ) );
		}
		if ( email_exists( $email ) ) {
			self::back( array( 'msg' => 'addexists' ) );
		}
		$id = self::create( $name, $email, $pass, true );
		self::back( array( 'msg' => is_wp_error( $id ) ? 'addfail' : 'added' ) );
	}

	private static function act_link( $u, $do, $label, $class = 'button', $confirm = '' ) {
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=ielts_desk_act&do=' . $do . '&user=' . $u->ID ), 'ielts_desk_act_' . $do . '_' . $u->ID );
		$on  = $confirm ? ' onclick="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ')"' : '';
		return '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '"' . $on . '>' . esc_html( $label ) . '</a> ';
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s     = self::settings();
		$q     = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$f     = isset( $_GET['f'] ) ? sanitize_key( $_GET['f'] ) : '';
		$paged = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );
		$per   = 50;
		$args  = array( 'role' => self::ROLE, 'number' => $per, 'paged' => $paged, 'orderby' => 'registered', 'order' => 'DESC', 'count_total' => true );
		if ( '' !== $q ) {
			$args['search']         = '*' . $q . '*';
			$args['search_columns'] = array( 'user_email', 'display_name', 'user_login' );
		}
		$meta = array(
			'blocked'    => array( 'key' => 'ielts_blocked', 'value' => '1' ),
			'unverified' => array( 'key' => 'ielts_verified', 'value' => '0' ),
			'pending'    => array( 'key' => 'ielts_approved', 'value' => '0' ),
		);
		if ( isset( $meta[ $f ] ) ) {
			$args['meta_query'] = array( $meta[ $f ] );
		}
		$uq    = new WP_User_Query( $args );
		$users = $uq->get_results();
		$total = (int) $uq->get_total();
		$all   = count( get_users( array( 'role' => self::ROLE, 'fields' => 'ID' ) ) );
		$notes = array(
			'saved' => 'Settings saved.', 'block' => 'Learner blocked and signed out on every device.', 'unblock' => 'Learner unblocked.',
			'approve' => 'Learner approved.', 'verify' => 'Email marked as confirmed.', 'delete' => 'Learner deleted.',
			'added' => 'Learner added. They can log in now with that email and password.', 'addbad' => 'Enter a name, a valid email and a password of at least 8 characters.',
			'addexists' => 'There is already an account with that email.', 'addfail' => 'Could not add the learner.', 'notlearner' => 'That is not a learner account.',
		);
		$msg  = isset( $_GET['msg'] ) ? sanitize_key( $_GET['msg'] ) : '';
		$base = admin_url( 'admin.php?page=ielts-desk' );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">IELTS Desk: learners</h1>
			<a href="#ielts-add" class="page-title-action">Add a learner</a>
			<hr class="wp-header-end">
			<?php if ( isset( $notes[ $msg ] ) ) : ?>
				<div class="notice <?php echo in_array( $msg, array( 'addbad', 'addexists', 'addfail', 'notlearner' ), true ) ? 'notice-error' : 'notice-success'; ?> is-dismissible"><p><?php echo esc_html( $notes[ $msg ] ); ?></p></div>
			<?php endif; ?>
			<p>Anyone can sign up in the app (<a href="<?php echo esc_url( self::APP_URL ); ?>" target="_blank" rel="noopener">ielts.iwate-bd.org</a>). They confirm their email with a 6-digit code or the link we email them. <strong><?php echo (int) $all; ?></strong> learner account(s) in total.</p>

			<ul class="subsubsub">
				<?php
				$tabs = array( '' => 'All', 'unverified' => 'Email not confirmed', 'blocked' => 'Blocked' );
				if ( $s['approve'] ) {
					$tabs['pending'] = 'Waiting for approval';
				}
				$i = 0;
				foreach ( $tabs as $k => $label ) {
					echo ( $i++ ? ' | ' : '' ) . '<li><a href="' . esc_url( add_query_arg( 'f', $k, $base ) ) . '"' . ( $f === $k ? ' class="current"' : '' ) . '>' . esc_html( $label ) . '</a></li>';
				}
				?>
			</ul>
			<form method="get" style="float:right;margin:6px 0">
				<input type="hidden" name="page" value="ielts-desk"><input type="hidden" name="f" value="<?php echo esc_attr( $f ); ?>">
				<input type="search" name="s" value="<?php echo esc_attr( $q ); ?>" placeholder="Name or email">
				<button class="button">Search</button>
			</form>
			<table class="widefat striped" style="clear:both">
				<thead><tr><th>Name</th><th>Email</th><th>Joined</th><th>Last seen</th><th>Status</th><th>Actions</th></tr></thead>
				<tbody>
				<?php if ( ! $users ) : ?>
					<tr><td colspan="6">No learners here yet.</td></tr>
				<?php endif; ?>
				<?php foreach ( $users as $u ) :
					$last  = (int) get_user_meta( $u->ID, 'ielts_last', true );
					$st    = array();
					$block = self::blocked( $u );
					if ( $block ) {
						$st[] = '<strong style="color:#b32d2e">Blocked</strong>';
					}
					if ( ! self::verified( $u ) ) {
						$st[] = '<span style="color:#996800">Email not confirmed</span>';
					}
					if ( $s['approve'] && ! self::approved( $u ) ) {
						$st[] = '<span style="color:#996800">Waiting for approval</span>';
					}
					if ( ! $st ) {
						$st[] = '<span style="color:#008a20">Active</span>';
					}
					if ( '1' === (string) get_user_meta( $u->ID, 'ielts_by_admin', true ) ) {
						$st[] = '<span class="description">added by admin</span>';
					}
					?>
					<tr>
						<td><?php echo esc_html( $u->display_name ); ?></td>
						<td><?php echo esc_html( $u->user_email ); ?></td>
						<td><?php echo esc_html( mysql2date( 'Y-m-d', $u->user_registered ) ); ?></td>
						<td><?php echo $last ? esc_html( human_time_diff( $last ) . ' ago' ) : '—'; ?></td>
						<td><?php echo wp_kses_post( implode( '<br>', $st ) ); ?></td>
						<td>
							<?php
							// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts
							echo $block ? self::act_link( $u, 'unblock', 'Unblock' ) : self::act_link( $u, 'block', 'Block', 'button', 'Block ' . $u->user_email . '? They are signed out at once and cannot log in.' );
							if ( ! self::verified( $u ) ) {
								echo self::act_link( $u, 'verify', 'Mark email confirmed' );   // phpcs:ignore
							}
							if ( $s['approve'] && ! self::approved( $u ) ) {
								echo self::act_link( $u, 'approve', 'Approve', 'button button-primary' );   // phpcs:ignore
							}
							echo self::act_link( $u, 'delete', 'Delete', 'button-link-delete', 'Delete ' . $u->user_email . ' and all their saved progress? This cannot be undone.' );   // phpcs:ignore
							?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php
			$pages = (int) ceil( $total / $per );
			if ( $pages > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( array(
					'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => $pages,
				) ) ) . '</div></div>';
			}
			?>

			<h2 id="ielts-add" style="margin-top:32px">Add a learner</h2>
			<p>Make an account for someone yourself. It works straight away (no email code), and they log in in the app with this email and password.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ielts_desk_add">
				<?php wp_nonce_field( 'ielts_desk_add' ); ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="ia-name">Name</label></th><td><input id="ia-name" name="name" class="regular-text" maxlength="60" required></td></tr>
					<tr><th scope="row"><label for="ia-email">Email</label></th><td><input id="ia-email" name="email" type="email" class="regular-text" required></td></tr>
					<tr><th scope="row"><label for="ia-pass">Password</label></th><td><input id="ia-pass" name="pass" type="text" class="regular-text" minlength="8" autocomplete="off" required>
						<button type="button" class="button" onclick="var a='abcdefghjkmnpqrstuvwxyz23456789',s='';for(var i=0;i<10;i++)s+=a[Math.floor(Math.random()*a.length)];document.getElementById('ia-pass').value=s">Make one</button>
						<p class="description">At least 8 characters. Give it to the learner yourself; they can change it later with "Forgot password?".</p></td></tr>
				</table>
				<?php submit_button( 'Add learner' ); ?>
			</form>

			<h2 style="margin-top:32px">Settings</h2>
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
						<td><label><input type="checkbox" name="approve" value="1" <?php checked( $s['approve'], 1 ); ?>> I approve each new account before it can log in</label>
							<p class="description">Leave this off to let everyone who confirms their email log in; you can still block anyone above.</p></td>
					</tr>
				</table>
				<?php submit_button( 'Save settings' ); ?>
			</form>
		</div>
		<?php
	}
}

IELTS_Desk_Accounts::init();
