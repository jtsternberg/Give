<?php
/**
 * Customer (Donors)
 *
 * @package     Give
 * @subpackage  Admin/Customers
 * @copyright   Copyright (c) 2016, WordImpress
 * @license     https://opensource.org/licenses/gpl-license GNU Public License
 * @since       1.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Processes a customer edit
 *
 * @since  1.0
 *
 * @param  array $args The $_POST array being passed
 *
 * @return array $output Response messages
 */
function give_edit_customer( $args ) {
	
	$customer_edit_role = apply_filters( 'give_edit_customers_role', 'edit_give_payments' );

	if ( ! is_admin() || ! current_user_can( $customer_edit_role ) ) {
		wp_die( esc_html__( 'You do not have permission to edit this donor.', 'give' ), esc_html__( 'Error', 'give' ), array( 'response' => 403 ) );
	}

	if ( empty( $args ) ) {
		return;
	}

	$customer_info = $args['customerinfo'];
	$customer_id   = (int) $args['customerinfo']['id'];
	$nonce         = $args['_wpnonce'];

	if ( ! wp_verify_nonce( $nonce, 'edit-customer' ) ) {
		wp_die( esc_html__( 'Cheatin&#8217; uh?', 'give' ), esc_html__( 'Error', 'give' ), array( 'response' => 400 ) );
	}

	$customer = new Give_Customer( $customer_id );
	if ( empty( $customer->id ) ) {
		return false;
	}

	$defaults = array(
		'name'    => '',
		'user_id' => 0
	);

	$customer_info = wp_parse_args( $customer_info, $defaults );

	if ( (int) $customer_info['user_id'] != (int) $customer->user_id ) {

		// Make sure we don't already have this user attached to a customer
		if ( ! empty( $customer_info['user_id'] ) && false !== Give()->customers->get_customer_by( 'user_id', $customer_info['user_id'] ) ) {
			give_set_error( 'give-invalid-customer-user_id', sprintf( esc_html__( 'The User ID %d is already associated with a different donor.', 'give' ), $customer_info['user_id'] ) );
		}

		// Make sure it's actually a user
		$user = get_user_by( 'id', $customer_info['user_id'] );
		if ( ! empty( $customer_info['user_id'] ) && false === $user ) {
			give_set_error( 'give-invalid-user_id', sprintf( esc_html__( 'The User ID %d does not exist. Please assign an existing user.', 'give' ), $customer_info['user_id'] ) );
		}

	}

	// Record this for later
	$previous_user_id = $customer->user_id;

	if ( give_get_errors() ) {
		return;
	}

	// Setup the customer address, if present
	$address = array();
	if ( intval( $customer_info['user_id'] ) > 0 ) {

		$current_address = get_user_meta( $customer_info['user_id'], '_give_user_address', true );

		if ( false === $current_address ) {
			$address['line1']   = isset( $customer_info['line1'] ) ? $customer_info['line1'] : '';
			$address['line2']   = isset( $customer_info['line2'] ) ? $customer_info['line2'] : '';
			$address['city']    = isset( $customer_info['city'] ) ? $customer_info['city'] : '';
			$address['country'] = isset( $customer_info['country'] ) ? $customer_info['country'] : '';
			$address['zip']     = isset( $customer_info['zip'] ) ? $customer_info['zip'] : '';
			$address['state']   = isset( $customer_info['state'] ) ? $customer_info['state'] : '';
		} else {
			$current_address    = wp_parse_args( $current_address, array(
				'line1',
				'line2',
				'city',
				'zip',
				'state',
				'country'
			) );
			$address['line1']   = isset( $customer_info['line1'] ) ? $customer_info['line1'] : $current_address['line1'];
			$address['line2']   = isset( $customer_info['line2'] ) ? $customer_info['line2'] : $current_address['line2'];
			$address['city']    = isset( $customer_info['city'] ) ? $customer_info['city'] : $current_address['city'];
			$address['country'] = isset( $customer_info['country'] ) ? $customer_info['country'] : $current_address['country'];
			$address['zip']     = isset( $customer_info['zip'] ) ? $customer_info['zip'] : $current_address['zip'];
			$address['state']   = isset( $customer_info['state'] ) ? $customer_info['state'] : $current_address['state'];
		}

	}

	// Sanitize the inputs
	$customer_data            = array();
	$customer_data['name']    = strip_tags( stripslashes( $customer_info['name'] ) );
	$customer_data['user_id'] = $customer_info['user_id'];

	$customer_data = apply_filters( 'give_edit_customer_info', $customer_data, $customer_id );
	$address       = apply_filters( 'give_edit_customer_address', $address, $customer_id );

	$customer_data = array_map( 'sanitize_text_field', $customer_data );
	$address       = array_map( 'sanitize_text_field', $address );


	/**
	 * Fires before editing customer.
	 *
	 * @since 1.0
	 *
	 * @param int   $customer_id   The ID of the customer.
	 * @param array $customer_data The customer data.
	 * @param array $address       The customer address.
	 */
	do_action( 'give_pre_edit_customer', $customer_id, $customer_data, $address );

	$output         = array();

	if ( $customer->update( $customer_data ) ) {

		if ( ! empty( $customer->user_id ) && $customer->user_id > 0 ) {
			update_user_meta( $customer->user_id, '_give_user_address', $address );
		}

		// Update some donation meta if we need to
		$payments_array = explode( ',', $customer->payment_ids );

		if ( $customer->user_id != $previous_user_id ) {
			foreach ( $payments_array as $payment_id ) {
				give_update_payment_meta( $payment_id, '_give_payment_user_id', $customer->user_id );
			}
		}

		$output['success']       = true;
		$customer_data           = array_merge( $customer_data, $address );
		$output['customer_info'] = $customer_data;

	} else {

		$output['success'] = false;

	}

	/**
	 * Fires after editing customer.
	 *
	 * @since 1.0
	 *
	 * @param int   $customer_id   The ID of the customer.
	 * @param array $customer_data The customer data.
	 */
	do_action( 'give_post_edit_customer', $customer_id, $customer_data );

	if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
		header( 'Content-Type: application/json' );
		echo json_encode( $output );
		wp_die();
	}

	return $output;

}

add_action( 'give_edit-customer', 'give_edit_customer', 10, 1 );

/**
 * Save a customer note being added
 *
 * @since  1.0
 *
 * @param  array $args The $_POST array being passeed
 *
 * @return int         The Note ID that was saved, or 0 if nothing was saved
 */
function give_customer_save_note( $args ) {

	$customer_view_role = apply_filters( 'give_view_customers_role', 'view_give_reports' );

	if ( ! is_admin() || ! current_user_can( $customer_view_role ) ) {
		wp_die( esc_html__( 'You do not have permission to edit this donor.', 'give' ), esc_html__( 'Error', 'give' ), array( 'response' => 403 ) );
	}

	if ( empty( $args ) ) {
		return;
	}

	$customer_note = trim( sanitize_text_field( $args['customer_note'] ) );
	$customer_id   = (int) $args['customer_id'];
	$nonce         = $args['add_customer_note_nonce'];

	if ( ! wp_verify_nonce( $nonce, 'add-customer-note' ) ) {
		wp_die( esc_html__( 'Cheatin&#8217; uh?', 'give' ), esc_html__( 'Error', 'give' ), array( 'response' => 400 ) );
	}

	if ( empty( $customer_note ) ) {
		give_set_error( 'empty-customer-note', esc_html__( 'A note is required.', 'give' ) );
	}

	if ( give_get_errors() ) {
		return;
	}

	$customer = new Give_Customer( $customer_id );
	$new_note = $customer->add_note( $customer_note );

	/**
	 * Fires before inserting customer note.
	 *
	 * @since 1.0
	 *
	 * @param int    $customer_id The ID of the customer.
	 * @param string $new_note    Note content.
	 */
	do_action( 'give_pre_insert_customer_note', $customer_id, $new_note );

	if ( ! empty( $new_note ) && ! empty( $customer->id ) ) {

		ob_start();
		?>
		<div class="customer-note-wrapper dashboard-comment-wrap comment-item">
			<span class="note-content-wrap">
				<?php echo stripslashes( $new_note ); ?>
			</span>
		</div>
		<?php
		$output = ob_get_contents();
		ob_end_clean();

		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			echo $output;
			exit;
		}

		return $new_note;

	}

	return false;

}

add_action( 'give_add-customer-note', 'give_customer_save_note', 10, 1 );

/**
 * Delete a customer
 *
 * @since  1.0
 *
 * @param  array $args The $_POST array being passed
 *
 * @return int Whether it was a successful deletion
 */
function give_customer_delete( $args ) {

	$customer_edit_role = apply_filters( 'give_edit_customers_role', 'edit_give_payments' );

	if ( ! is_admin() || ! current_user_can( $customer_edit_role ) ) {
		wp_die( esc_html__( 'You do not have permission to delete donors.', 'give' ), esc_html__( 'Error', 'give' ), array( 'response' => 403 ) );
	}

	if ( empty( $args ) ) {
		return;
	}

	$customer_id = (int) $args['customer_id'];
	$confirm     = ! empty( $args['give-customer-delete-confirm'] ) ? true : false;
	$remove_data = ! empty( $args['give-customer-delete-records'] ) ? true : false;
	$nonce       = $args['_wpnonce'];

	if ( ! wp_verify_nonce( $nonce, 'delete-customer' ) ) {
		wp_die( esc_html__( 'Cheatin&#8217; uh?', 'give' ), esc_html__( 'Error', 'give' ), array( 'response' => 400 ) );
	}

	if ( ! $confirm ) {
		give_set_error( 'customer-delete-no-confirm', esc_html__( 'Please confirm you want to delete this donor.', 'give' ) );
	}

	if ( give_get_errors() ) {
		wp_redirect( admin_url( 'edit.php?post_type=give_forms&page=give-donors&view=overview&id=' . $customer_id ) );
		exit;
	}

	$customer = new Give_Customer( $customer_id );

	/**
	 * Fires before deleting customer.
	 *
	 * @since 1.0
	 *
	 * @param int  $customer_id The ID of the customer.
	 * @param bool $confirm     Delete confirmation.
	 * @param bool $remove_data Records delete confirmation.
	 */
	do_action( 'give_pre_delete_customer', $customer_id, $confirm, $remove_data );
	
	if ( $customer->id > 0 ) {

		$payments_array = explode( ',', $customer->payment_ids );
		$success        = Give()->customers->delete( $customer->id );

		if ( $success ) {

			if ( $remove_data ) {

				// Remove all donations, logs, etc
				foreach ( $payments_array as $payment_id ) {
					give_delete_purchase( $payment_id );
				}

			} else {

				// Just set the donations to customer_id of 0
				foreach ( $payments_array as $payment_id ) {
					give_update_payment_meta( $payment_id, '_give_payment_customer_id', 0 );
				}

			}

			$redirect = admin_url( 'edit.php?post_type=give_forms&page=give-donors&give-message=customer-deleted' );

		} else {

			give_set_error( 'give-donor-delete-failed', esc_html__( 'Error deleting donor.', 'give' ) );
			$redirect = admin_url( 'edit.php?post_type=give_forms&page=give-donors&view=delete&id=' . $customer_id );

		}

	} else {

		give_set_error( 'give-customer-delete-invalid-id', esc_html__( 'Invalid Donor ID.', 'give' ) );
		$redirect = admin_url( 'edit.php?post_type=give_forms&page=give-donors' );

	}

	wp_redirect( $redirect );
	exit;

}

add_action( 'give_delete-customer', 'give_customer_delete', 10, 1 );

/**
 * Disconnect a user ID from a donor
 *
 * @since  1.0
 *
 * @param  array $args Array of arguments
 *
 * @return bool        If the disconnect was successful
 */
function give_disconnect_customer_user_id( $args ) {

	$customer_edit_role = apply_filters( 'give_edit_customers_role', 'edit_give_payments' );

	if ( ! is_admin() || ! current_user_can( $customer_edit_role ) ) {
		wp_die( esc_html__( 'You do not have permission to edit this donor.', 'give' ), esc_html__( 'Error', 'give' ), array( 'response' => 403 ) );
	}

	if ( empty( $args ) ) {
		return;
	}

	$customer_id = (int) $args['customer_id'];
	$nonce       = $args['_wpnonce'];

	if ( ! wp_verify_nonce( $nonce, 'edit-customer' ) ) {
		wp_die( esc_html__( 'Cheatin&#8217; uh?', 'give' ), esc_html__( 'Error', 'give' ), array( 'response' => 400 ) );
	}

	$customer = new Give_Customer( $customer_id );
	if ( empty( $customer->id ) ) {
		return false;
	}

	$user_id = $customer->user_id;

	/**
	 * Fires before disconnecting user ID from a donor.
	 *
	 * @since 1.0
	 *
	 * @param int $customer_id The ID of the customer.
	 * @param int $user_id     The ID of the user.
	 */
	do_action( 'give_pre_customer_disconnect_user_id', $customer_id, $user_id );

	$output = array();
	$customer_args = array( 'user_id' => 0 );

	if ( $customer->update( $customer_args ) ) {
		global $wpdb;

		if ( ! empty( $customer->payment_ids ) ) {
			$wpdb->query( "UPDATE $wpdb->postmeta SET meta_value = 0 WHERE meta_key = '_give_payment_user_id' AND post_id IN ( $customer->payment_ids )" );
		}

		$output['success'] = true;

	} else {

		$output['success'] = false;
		give_set_error( 'give-disconnect-user-fail', esc_html__( 'Failed to disconnect user from donor.', 'give' ) );
	}

	/**
	 * Fires after disconnecting user ID from a donor.
	 *
	 * @since 1.0
	 *
	 * @param int $customer_id The ID of the customer.
	 */
	do_action( 'give_post_customer_disconnect_user_id', $customer_id );

	if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
		header( 'Content-Type: application/json' );
		echo json_encode( $output );
		wp_die();
	}

	return $output;

}

add_action( 'give_disconnect-userid', 'give_disconnect_customer_user_id', 10, 1 );

/**
 * Add an email address to the donor from within the admin and log a donor note
 *
 * @since  1.7
 * @param  array $args  Array of arguments: nonce, customer id, and email address
 * @return mixed        If DOING_AJAX echos out JSON, otherwise returns array of success (bool) and message (string)
 */
function give_add_donor_email( $args ) {
	$customer_edit_role = apply_filters( 'give_edit_customers_role', 'edit_give_payments' );

	if ( ! is_admin() || ! current_user_can( $customer_edit_role ) ) {
		wp_die( esc_html__( 'You do not have permission to edit this donor.', 'edit' ) );
	}

	$output = array();
	if ( empty( $args ) || empty( $args['email'] ) || empty( $args['customer_id'] ) ) {
		$output['success'] = false;
		if ( empty( $args['email'] ) ) {
			$output['message'] = esc_html__( 'Email address is required.', 'give' );
		} else if ( empty( $args['customer_id'] ) ) {
			$output['message'] = esc_html__( 'Customer ID is required.', 'give' );
		} else {
			$output['message'] = esc_html__( 'An error has occurred. Please try again.', 'give' );
		}
	} else if ( ! wp_verify_nonce( $args['_wpnonce'], 'give_add_donor_email' ) ) {
		$output = array(
			'success' => false,
			'message' => esc_html__( 'Nonce verification failed.', 'give' ),
		);
	} else if ( ! is_email( $args['email'] ) ) {
		$output = array(
			'success' => false,
			'message' => esc_html__( 'Invalid email address.', 'give' ),
		);
	} else {
		$email       = sanitize_email($args['email'] );
		$customer_id = (int) $args['customer_id'];
		$primary     = 'true' === $args['primary'] ? true : false;
		$customer    = new Give_Customer( $customer_id );
		if ( false === $customer->add_email( $email, $primary ) ) {
			if ( in_array( $email, $customer->emails ) ) {
				$output = array(
					'success'  => false,
					'message'  => esc_html__( 'Email already associated with this donor.', 'give' ),
				);
			} else {
				$output = array(
					'success' => false,
					'message' => esc_html__( 'Email address is already associated with another donor.', 'give' ),
				);
			}
		} else {
			$redirect = admin_url( 'edit.php?post_type=give_forms&page=give-donors&view=overview&id=' . $customer_id . '&give-message=email-added' );
			$output = array(
				'success'  => true,
				'message'  => esc_html__( 'Email successfully added to donor.', 'give' ),
				'redirect' => $redirect,
			);

			$user          = wp_get_current_user();
			$user_login    = ! empty( $user->user_login ) ? $user->user_login : esc_html__( 'System', 'give' );
			$customer_note = sprintf( __( 'Email address %s added by %s', 'give' ), $email, $user_login );
			$customer->add_note( $customer_note );

			if ( $primary ) {
				$customer_note = sprintf( __( 'Email address %s set as primary by %s', 'give' ), $email, $user_login );
				$customer->add_note( $customer_note );
			}
		}
	}

	do_action( 'give_post_add_customer_email', $customer_id, $args );

	if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
		header( 'Content-Type: application/json' );
		echo json_encode( $output );
		wp_die();
	}

	return $output;
}
add_action( 'give_add_donor_email', 'give_add_donor_email', 10, 1 );


/**
 * Remove an email address to the donor from within the admin and log a donor note
 * and redirect back to the donor interface for feedback
 *
 * @since  1.7
 * @return void|bool
 */
function give_remove_donor_email() {
	if ( empty( $_GET['id'] ) || ! is_numeric( $_GET['id'] ) ) {
		return false;
	}
	if ( empty( $_GET['email'] ) || ! is_email( $_GET['email'] ) ) {
		return false;
	}
	if ( empty( $_GET['_wpnonce'] ) ) {
		return false;
	}

	$nonce = $_GET['_wpnonce'];
	if ( ! wp_verify_nonce( $nonce, 'give-remove-donor-email' ) ) {
		wp_die( esc_html__( 'Nonce verification failed', 'give' ), esc_html__( 'Error', 'give' ), array( 'response' => 403 ) );
	}

	$customer = new Give_Customer( $_GET['id'] );
	if ( $customer->remove_email( $_GET['email'] ) ) {
		$url = add_query_arg( 'give-message', 'email-removed', admin_url( 'edit.php?post_type=give_forms&page=give-donors&view=overview&id=' . $customer->id ) );
		$user          = wp_get_current_user();
		$user_login    = ! empty( $user->user_login ) ? $user->user_login : esc_html__( 'System', 'give' );
		$customer_note = sprintf( __( 'Email address %s removed by %s', 'give' ), $_GET['email'], $user_login );
		$customer->add_note( $customer_note );
	} else {
		$url = add_query_arg( 'give-message', 'email-remove-failed', admin_url( 'edit.php?post_type=give_forms&page=give-donors&view=overview&id=' . $customer->id ) );
	}

	wp_safe_redirect( $url );
	exit;
}
add_action( 'give_remove_donor_email', 'give_remove_donor_email', 10 );


/**
 * Set an email address as the primary for a donor from within the admin and log a donor note
 * and redirect back to the donor interface for feedback
 *
 * @since  1.7
 * @return void|bool
 */
function give_set_donor_primary_email() {
	if ( empty( $_GET['id'] ) || ! is_numeric( $_GET['id'] ) ) {
		return false;
	}

	if ( empty( $_GET['email'] ) || ! is_email( $_GET['email'] ) ) {
		return false;
	}

	if ( empty( $_GET['_wpnonce'] ) ) {
		return false;
	}

	$nonce = $_GET['_wpnonce'];

	if ( ! wp_verify_nonce( $nonce, 'give-set-donor-primary-email' ) ) {
		wp_die( esc_html__( 'Nonce verification failed', 'give' ), esc_html__( 'Error', 'give' ), array( 'response' => 403 ) );
	}

	$donor = new Give_Customer( $_GET['id'] );

	if ( $donor->set_primary_email( $_GET['email'] ) ) {
		$url = add_query_arg( 'give-message', 'primary-email-updated', admin_url( 'edit.php?post_type=give_forms&page=give-donors&view=overview&id=' . $donor->id ) );
		$user          = wp_get_current_user();
		$user_login    = ! empty( $user->user_login ) ? $user->user_login : esc_html__( 'System', 'give' );
		$donor_note    = sprintf( __( 'Email address %s set as primary by %s', 'give' ), $_GET['email'], $user_login );

		$donor->add_note( $donor_note );
	} else {
		$url = add_query_arg( 'give-message', 'primary-email-failed', admin_url( 'edit.php?post_type=give_forms&page=give-donors&view=overview&id=' . $donor->id ) );
	}

	wp_safe_redirect( $url );
	exit;
}
add_action( 'give_set_donor_primary_email', 'give_set_donor_primary_email', 10 );
