<?php

if ( !function_exists('wp_new_user_notification') ) :
/**
 * Notify the blog admin of a new user, normally via email.
 *
 * @param int $user_id User ID
 * @param string $plaintext_pass Optional. The user's plaintext password
 */
function wp_new_user_notification($user_id, $plaintext_pass = '') {
	if (isset($_POST["cimy_uef_wp_PASSWORD"]))
		delete_usermeta($user_id, 'default_password_nag');

	if (!is_multisite()) {
		if (isset($_POST["cimy_uef_wp_PASSWORD"]))
			$plaintext_pass = $_POST["cimy_uef_wp_PASSWORD"];

		$options = cimy_get_options();
		if (!$options["confirm_email"])
			wp_new_user_notification_original($user_id, $plaintext_pass, $options["mail_include_fields"]);
		// if confirmation email is enabled delete the default_password_nag but checks first if has not been done on top of this function!
		else if (!isset($_POST["cimy_uef_wp_PASSWORD"]))
			delete_usermeta($user_id, 'default_password_nag');
	}
	else
		wp_new_user_notification_original($user_id, $plaintext_pass);
}
endif;

function wp_new_user_notification_original($user_id, $plaintext_pass = '', $include_fields = false) {
	$user = new WP_User($user_id);

	$user_login = stripslashes($user->user_login);
	$user_email = stripslashes($user->user_email);

	// The blogname option is escaped with esc_html on the way into the database in sanitize_option
	// we want to reverse this for the plain text arena of emails.
	$blogname = wp_specialchars_decode(get_option('blogname'), ENT_QUOTES);

	$message  = sprintf(__('New user registration on your site %s:'), $blogname) . "\r\n\r\n";
	$message .= sprintf(__('Username: %s'), $user_login) . "\r\n\r\n";
	$message .= sprintf(__('E-mail: %s'), $user_email) . "\r\n";

	@wp_mail(get_option('admin_email'), sprintf(__('[%s] New User Registration'), $blogname), $message);

	if ( empty($plaintext_pass) )
		return;

	$message  = sprintf(__('Username: %s'), $user_login) . "\r\n";
	$message .= sprintf(__('Password: %s'), $plaintext_pass) . "\r\n";
	$message .= wp_login_url() . "\r\n";

	wp_mail($user_email, sprintf(__('[%s] Your username and password'), $blogname), $message);
}

function cimy_signup_user_notification($user, $user_email, $key, $meta = '') {
	global $cuef_plugin_path;

	if ( !apply_filters('wpmu_signup_user_notification', $user, $user_email, $key, $meta) )
		return false;

	// Send email with activation link.
	$admin_email = get_site_option( 'admin_email' );
	if ( $admin_email == '' )
		$admin_email = 'support@' . $_SERVER['SERVER_NAME'];
	$from_name = get_site_option( 'site_name' ) == '' ? 'WordPress' : esc_html( get_site_option( 'site_name' ) );
	$message_headers = "From: \"{$from_name}\" <{$admin_email}>\n" . "Content-Type: text/plain; charset=\"" . get_option('blog_charset') . "\"\n";
	$message = sprintf( apply_filters( 'wpmu_signup_user_notification_email', __( "To activate your user, please click the following link:\n\n%s\n\nAfter you activate, you will receive *another email* with your login.\n\n" ) ), site_url( "wp-login.php?cimy_key=$key" ), $key );
	// TODO: Don't hard code activation link.
	$subject = sprintf( __( apply_filters( 'wpmu_signup_user_notification_subject', '[%1$s] Activate %2$s' ) ), $from_name, $user);
	wp_mail($user_email, $subject, $message, $message_headers);
	return true;
}

function cimy_uef_activate($message) {
	global $wpdb;
	if (isset($_GET["cimy_key"])) {
		$result = cimy_uef_activate_signup($_GET["cimy_key"]);

		if ( is_wp_error($result) ) {
			if ( 'already_active' == $result->get_error_code()) {
				$signup = $result->get_error_data();
				$message = '<p class="message"><strong>'.__('Your account is now active!').'</strong><br />';
				$message.= sprintf( __('Your site at <a href="%1$s">%2$s</a> is active. You may now log in to your site using your chosen username of &#8220;%3$s&#8221;.  Please check your email inbox at %4$s for your password and login instructions.  If you do not receive an email, please check your junk or spam folder.  If you still do not receive an email within an hour, you can <a href="%5$s">reset your password</a></p>.'), 'http://' . $signup->domain, $signup->domain, $signup->user_login, $signup->user_email, network_site_url( 'wp-login.php?action=lostpassword' ) );
			} else {
				$message = '<p class="message"><strong>'.__('An error occurred during the activation').'</strong><br />';
				$message.= $result->get_error_message().'</p>';
			}
		} else {
			extract($result);
			$user = new WP_User( (int) $user_id);
			$message = '<p class="message"><strong>'.__('Your account is now active!').'</strong><br />'.__('Username:').' '.$user->user_login.'<br />'.__('Password:').' '.$password.'</p>';
		}
	}
	return $message;
}

function cimy_uef_activate_signup($key) {
	global $wpdb, $current_site;

	require_once( ABSPATH . WPINC . '/registration.php');
	$signup = $wpdb->get_row( $wpdb->prepare("SELECT * FROM ".$wpdb->prefix."signups WHERE activation_key = %s", $key) );

	if ( empty($signup) )
		return new WP_Error('invalid_key', __('Invalid activation key.'));

	if ( $signup->active )
		return new WP_Error('already_active', __('The site is already active.'), $signup);

	$meta = unserialize($signup->meta);
	$user_login = $wpdb->escape($signup->user_login);
	$user_email = $wpdb->escape($signup->user_email);

	if (!empty($meta["cimy_uef_wp_PASSWORD"]))
		$password = $meta["cimy_uef_wp_PASSWORD"];
	else
		$password = wp_generate_password();

	$user_id = username_exists($user_login);

	if ( ! $user_id )
		$user_id = wp_create_user( $user_login, $password, $user_email );
	else
		$user_already_exists = true;

	if ( ! $user_id )
		return new WP_Error('create_user', __('Could not create user'), $signup);
	else
		cimy_register_user_extra_fields($user_id, $password, $meta);

	if ((empty($meta["cimy_uef_wp_PASSWORD"])) && ($user_already_exists))
		update_user_option( $user_id, 'default_password_nag', true, true ); //Set up the Password change nag.

	$now = current_time('mysql', true);

	$wpdb->update( $wpdb->prefix."signups", array('active' => 1, 'activated' => $now), array('activation_key' => $key) );

	if ( isset( $user_already_exists ) )
		return new WP_Error( 'user_already_exists', __( 'That username is already activated.' ), $signup);

	wp_new_user_notification_original($user_id, $password);
	return array('user_id' => $user_id, 'password' => $password, 'meta' => $meta);
}

function cimy_check_user_on_signups($errors, $user_name, $user_email) {
	global $wpdb;

	// Has someone already signed up for this username?
	$signup = $wpdb->get_row( $wpdb->prepare("SELECT * FROM ".$wpdb->prefix."signups WHERE user_login = %s", $user_name) );
	if ( $signup != null ) {
		$registered_at =  mysql2date('U', $signup->registered);
		$now = current_time( 'timestamp', true );
		$diff = $now - $registered_at;
		// If registered more than two days ago, cancel registration and let this signup go through.
		if ( $diff > 172800 )
			$wpdb->query( $wpdb->prepare("DELETE FROM ".$wpdb->prefix."signups WHERE user_login = %s", $user_name) );
		else
			$errors->add('user_name', __('That username is currently reserved but may be available in a couple of days.'));

		if ( $signup->active == 0 && $signup->user_email == $user_email )
			$errors->add('user_email_used', __('username and email used'));
	}

	$signup = $wpdb->get_row( $wpdb->prepare("SELECT * FROM ".$wpdb->prefix."signups WHERE user_email = %s", $user_email) );
	if ( $signup != null ) {
		$diff = current_time( 'timestamp', true ) - mysql2date('U', $signup->registered);
		// If registered more than two days ago, cancel registration and let this signup go through.
		if ( $diff > 172800 )
			$wpdb->query( $wpdb->prepare("DELETE FROM ".$wpdb->prefix."signups WHERE user_email = %s", $user_email) );
		else
			$errors->add('user_email', __('That email address has already been used. Please check your inbox for an activation email. It will become available in a couple of days if you do nothing.'));
	}

	return $errors;
}
?>
