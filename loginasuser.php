<?php
/*
Plugin Name: Login As User
Plugin URI: http://kynatro.com/
Description: Adds a column to the Users admin table that adds a button for Super Admins to login as a user
Version: 1.0
Author: kynatro
Author URI: http://kynatro.com
Contributors: kynatro
License: GPL3

Copyright 2012 digital-telepathy  (email : support@digital-telepathy.com)

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

class LoginAsUser {
    /**
     * Private method for generating the URL of the "login as" AJAX end-point
     *
     * Creates a nonce'd URL that points to the WordPress AJAX end-point specifying
     * the "login_as_user" action for this plugin that allows super administrators to
     * login as a different user.
     *
     * @param integer $user_id The ID of the user to login as
     *
     * @uses wp_nonce_url()
     * @uses admin_url()
     *
     * @return string
     */
    static private function _url( $user_id ) {
        $url = wp_nonce_url( admin_url( 'admin-ajax.php?action=login_as_user&user_id=' . $user_id ), 'login_as_user' );

        return $url;
    }

    /**
     * Hook into edit_user_profile action
     *
     * Outputs a "Login as User" button on the edit profile page of a user. Does not output
     * the button if you are viewing your own profile page or if you are not a Super Administrator.
     *
     * @global $current_user
     *
     * @uses get_currentuserinfo()
     * @uses is_super_admin()
     * @uses LoginAsUser::_url()
     */
    static function edit_user_profile( $profileuser ) {
        global $current_user;

        // Update the $current_user global with current data
        get_currentuserinfo();

        // Don't output anything if the currently logged in user is not a Super Administrator
        if( !is_super_admin() ) {
            return false;
        }

        // Don't output the button if you're viewing another Super Administrator's profile
        if( is_super_admin( $profileuser->ID ) ) {
            return false;
        }

        // Not much point in showing the "Login as User" button if you're viewing your profile...
        if( $current_user != $profileuser ){
            echo '<h3>Super Administration</h3><table class="form-table"><tbody><tr><th>Login as this user</th><td><a href="' . self::_url( $user_id ) . '" class="button">Login as User</a></td></tr></tbody></table>';
        }
    }

    /**
     * Hook into the manage_users_column filter
     *
     * Appends a new header to the manage Users table in the admin control panel. This
     * filter can be found as a polymorphic filter name in the /wp-admin/includs/list-table.php
     * file in the _WP_List_Table_Compat PHP Class constructor.
     *
     * The filter receives one property: an array of the column headers. The keys in the
     * array is what is used to identify the column in the filter for outputting your custom
     * column data. The value of each Array item is the label of the column header.
     *
     * @param array $cols Associative Array of column names and labels
     *
     * @uses is_super_admin()
     *
     * @return array
     */
    static function manage_users_columns( $cols = false ) {
        // Check that only Super Administrators can even see this column
        if( is_super_admin() ) {
            /*
             * Single item Array for our new column. The key of the single item will
             * be the column name to refer to when we hook in to add the column data.
             * The value of the item will be the label output in the table headers.
             */
            $login_as_col = array(
                'login-as-user' => "Login"
            );

            // Merge in our single column array with the existing columns
            $cols = array_merge( $cols, $login_as_col );
        }

        // Always return the columns value since this is a filter
        return $cols;
    }

    /**
     * Hook into the manage_users_custom_column filter
     *
     * Adds the "Login as User" button in the new column created with the manage_users_columns
     * hook above. This filter receives data about all columns in the Users table and can be
     * used to modify data output by other plugins with a higher filter priority or add your
     * own custom column data.
     *
     * @param string $value The default value to be output in the column
     * @param string $column_name The name of the column (as defined in your manage_users_columns hook above)
     * @param integer $user_id The ID of the user of the current row being output
     *
     * @uses is_super_admin()
     * @uses LoginAsUser::_url()
     *
     * @return string
     */
    static function manage_users_custom_column( $value, $column_name, $user_id ) {
        // Check to make sure this is our column AND that the currently logged in user is a Super Administrator
        if( $column_name == "login-as-user" && is_super_admin() ) {
            // Change the value of this column to be our "Login as User" button
            $value = '<a href="' . self::_url( $user_id ) . '" class="button">Login as User</a>';
        }

        // Always return the value since this is a filter
        return $value;
    }

    /**
     * Hook into wpsc_billing_details_bottom
     *
     * For WP E-Commerce installations, this will output a "Login as User" button in a purchase log's
     * detail view. Unfortunately, the Store Sales table is not hookable to output the button in the
     * table itself.
     *
     * @global $wpdb
     *
     * @uses is_super_admin()
     * @uses wpdb::get_var()
     * @uses wpdb::prepare()
     * @uses LoginAsUser::_url()
     */
    static function wpsc_billing_details_bottom() {
        global $wpdb;

        // Do not output this button if the currently logged in user is not a Super Administrator
        if( !is_super_admin() ) {
            return false;
        }

        // Sanitize the Purchase Log ID from the URL
        $purchaseid = intval ( $_REQUEST['id'] );
        // Look up the WordPress User ID for the purchase
        $sql = "SELECT user_ID FROM `" . WPSC_TABLE_PURCHASE_LOGS . "` WHERE `id` = %d LIMIT 1";
        $user_id = $wpdb->get_var( $wpdb->prepare( $sql, $purchaseid ) );

        // Echo out the button to the view
        echo '<p><a href="' . self::_url( $user_id ) . '" class="button">Login as User</a></p>';
    }

    /**
     * WordPress AJAX hook-in
     *
     * This is the AJAX response for the wp_ajax_login_as_user action. This will verify
     * the nonce in the request for security and only act if the currently logged in user
     * is a Super Administrator.
     *
     * After security verification, this action changes the logged in Super Administrator's
     * logged in user authorization to that of the requested user with all of that user's
     * capabilities and restrictions.
     *
     * @uses wp_verify_nonce()
     * @uses is_super_admin()
     * @uses WP_User()
     * @uses wp_set_current_user()
     * @uses wp_set_auth_cookie()
     * @uses do_action()
     * @uses wp_redirect()
     */
    static function login_as_user() {
        // Only act if the nonce verifies (i.e. that this request came from this website)
        if( !wp_verify_nonce( $_REQUEST['_wpnonce'], 'login_as_user' ) ) {
            die( "false" );
        }
        // Only act if the currently logged in user is a Super Administrator
        if( !is_super_admin() ) {
            die( "false" );
        }

        // Sanitize the requested User ID
        $user_id = intval( $_REQUEST['user_id'] );

        // Don't allow logging in as another Super Administrator
        if( is_super_admin( $user_id ) ) {
            die( "false" );
        }

        // Look up the requested User
        $user = new WP_User( $user_id );

        // Set the currently logged in user to the requested User
        wp_set_current_user( $user->ID, $user->user_login );
        // Change the currently logged in user's auth cookies
        wp_set_auth_cookie( $user->ID );
        // Do any other login actions
        do_action( 'wp_login', $user->user_login );

        // Redirect to the home page
        wp_redirect( '/' );

        // Always exit after initiating wp_redirect() or you run into "Headers Already Sent" errors
        exit;
    }
}

// Add the "Login as User" button to the edit user view
add_action( 'edit_user_profile', array( 'LoginAsUser', 'edit_user_profile' ) );
// Hook in to add the "Login" column to the User Management table
add_filter( 'manage_users_columns', array( 'LoginAsUser', 'manage_users_columns' ) );
// Hook in to add the "Login as User" button to the User Management table
add_filter( 'manage_users_custom_column', array( 'LoginAsUser', 'manage_users_custom_column' ), 20, 3 );
// Add the WP AJAX response action for logging in as a user
add_action( 'wp_ajax_login_as_user', array( 'LoginAsUser', 'login_as_user' ) );
// Add the "Login as User" button to the bottom of WP E-Commerce purchase log detail pages
add_action( 'wpsc_billing_details_bottom', array( 'LoginAsUser', 'wpsc_billing_details_bottom' ) );
