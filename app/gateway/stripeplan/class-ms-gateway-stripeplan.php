<?php
/**
 * @copyright Incsub (http://incsub.com/)
 *
 * @license http://opensource.org/licenses/GPL-2.0 GNU General Public License, version 2 (GPL-2.0)
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License, version 2, as
 * published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston,
 * MA 02110-1301 USA
 *
*/

/**
 * Stripe Gateway Integration for repeated payments (payment plans).
 *
 * Persisted by parent class MS_Model_Option. Singleton.
 *
 * @since 2.0.0
 * @package Membership2
 * @subpackage Model
 */
class MS_Gateway_Stripeplan extends MS_Gateway {

	const ID = 'stripeplan';

	/**
	 * Gateway singleton instance.
	 *
	 * @since 2.0.0
	 * @var string $instance
	 */
	public static $instance;

	/**
	 * Instance of the shared stripe API integration
	 *
	 * @since 2.0.0
	 * @var MS_Gateway_Stripe_Api $api
	 */
	protected $_api;

	/**
	 * Initialize the object.
	 *
	 * @since 2.0.0
	 */
	public function after_load() {
		parent::after_load();
		$this->_api = MS_Factory::load( 'MS_Gateway_Stripe_Api' );

		$this->id = self::ID;
		$this->name = __( 'Stripe Subscriptions Gateway', MS_TEXT_DOMAIN );
		$this->group = 'Stripe';
		$this->manual_payment = false;
		$this->pro_rate = true;
		$this->unsupported_payment_types = array(
			MS_Model_Membership::PAYMENT_TYPE_PERMANENT,
			MS_Model_Membership::PAYMENT_TYPE_FINITE,
			MS_Model_Membership::PAYMENT_TYPE_DATE_RANGE,
		);

		// Update all payment plans and coupons.
		$this->add_action(
			'ms_gateway_toggle_stripeplan',
			'update_stripe_data'
		);

		// Update a single payment plan.
		$this->add_action(
			'ms_saved_MS_Model_Membership',
			'update_stripe_data_membership'
		);

		// Update a single coupon.
		$this->add_action(
			'ms_saved_MS_Addon_Coupon_Model',
			'update_stripe_data_coupon'
		);
	}

	/**
	 * Creates the external Stripe-ID of the specified item.
	 *
	 * This ID takes the current WordPress Site-URL into account to avoid
	 * collissions when several Membership2 sites use the same stripe account.
	 *
	 * @since  2.0.0
	 * @api
	 *
	 * @param  int $id The internal ID.
	 * @param  string $type The item type, e.g. 'plan' or 'coupon'.
	 * @return string The external Stripe-ID.
	 */
	static public function get_the_id( $id, $type = 'item' ) {
		static $Base = null;
		if ( null === $Base ) {
			$Base = get_option( 'site_url' );
		}

		$hash = strtolower( md5( $Base . $type . $id ) );
		$hash = lib2()->convert(
			$hash,
			'0123456789abcdef',
			'0123456789ABCDEFGHIJKLMNOPQRSTUVXXYZabcdefghijklmnopqrstuvxxyz'
		);
		$result = 'ms-' . $type . '-' . $id . '-' . $hash;
		return $result;
	}

	/**
	 * Checks all Memberships and creates/updates the payment plan on stripe if
	 * the membership changed since the plan was last changed.
	 *
	 * This function is called when the gateway is activated and after a
	 * membership was saved to database.
	 *
	 * @since  2.0.0
	 */
	public function update_stripe_data() {
		if ( ! $this->active ) { return false; }
		$this->_api->mode = $this->mode;

		// 1. Update all playment plans.
		$memberships = MS_Model_Membership::get_memberships();
		foreach ( $memberships as $membership ) {
			$this->update_stripe_data_membership( $membership );
		}

		// 2. Update all coupons (if Add-on is enabled)
		if ( MS_Addon_Coupon::is_active() ) {
			$coupons = MS_Addon_Coupon_Model::get_coupons();
			foreach ( $coupons as $coupon ) {
				$this->update_stripe_data_coupon( $coupon );
			}
		}
	}

	/**
	 * Creates or updates a single payment plan on Stripe.
	 *
	 * This function is called when the gateway is activated and after a
	 * membership was saved to database.
	 *
	 * @since  2.0.0
	 */
	public function update_stripe_data_membership( $membership ) {
		if ( ! $this->active ) { return false; }
		$this->_api->mode = $this->mode;

		$plan_data = array(
			'id' => self::get_the_id( $membership->id, 'plan' ),
			'amount' => 0,
		);

		if ( ! $membership->is_free()
			&& $membership->payment_type == MS_Model_Membership::PAYMENT_TYPE_RECURRING
		) {
			// Prepare the plan-data for Stripe.
			$trial_days = null;
			if ( MS_Model_Addon::is_enabled( MS_Model_Addon::ADDON_TRIAL )
				&& $membership->trial_period_enabled
			) {
				$trial_days = MS_Helper_Period::get_period_in_days(
					$membership->trial_period_unit,
					$membership->trial_period_type
				);
			}

			$interval = 'day';
			$max_count = 365;
			switch ( $membership->pay_cycle_period_type ) {
				case MS_Helper_Period::PERIOD_TYPE_WEEKS:
					$interval = 'week';
					$max_count = 52;
					break;

				case MS_Helper_Period::PERIOD_TYPE_MONTHS:
					$interval = 'month';
					$max_count = 12;
					break;

				case MS_Helper_Period::PERIOD_TYPE_YEARS:
					$interval = 'year';
					$max_count = 1;
					break;
			}

			$interval_count = min(
				$max_count,
				$membership->pay_cycle_period_unit
			);

			$settings = MS_Plugin::instance()->settings;
			$plan_data['amount'] = absint( $membership->price * 100 );
			$plan_data['currency'] = $settings->currency;
			$plan_data['name'] = $membership->name;
			$plan_data['interval'] = $interval;
			$plan_data['interval_count'] = $interval_count;
			$plan_data['trial_period_days'] = $trial_days;

			// Check if the plan needs to be updated.
			$serialized_data = json_encode( $plan_data );
			$temp_key = substr( 'ms-stripe-' . $plan_data['id'], 0, 45 );
			$temp_data = MS_Factory::get_transient( $temp_key );

			if ( $temp_data != $serialized_data ) {
				MS_Factory::set_transient(
					$temp_key,
					$serialized_data,
					HOUR_IN_SECONDS
				);

				$this->_api->create_or_update_plan( $plan_data );
			}
		}
	}

	/**
	 * Creates or updates a single coupon on Stripe.
	 *
	 * This function is called when the gateway is activated and after a
	 * coupon was saved to database.
	 *
	 * @since  2.0.0
	 */
	public function update_stripe_data_coupon( $coupon ) {
		if ( ! $this->active ) { return false; }
		$this->_api->mode = $this->mode;

		$settings = MS_Plugin::instance()->settings;
		$duration = 'once';
		$percent_off = null;
		$amount_off = null;

		if ( MS_Addon_Coupon_Model::TYPE_VALUE == $coupon->discount_type ) {
			$amount_off = absint( $coupon->discount * 100 );
		} else {
			$percent_off = $coupon->discount;
		}

		$coupon_data = array(
			'id' => self::get_the_id( $coupon->id, 'coupon' ),
			'duration' => $duration,
			'amount_off' => $amount_off,
			'percent_off' => $percent_off,
			'currency' => $settings->currency,
		);

		// Check if the plan needs to be updated.
		$serialized_data = json_encode( $coupon_data );
		$temp_key = substr( 'ms-stripe-' . $coupon_data['id'], 0, 45 );
		$temp_data = MS_Factory::get_transient( $temp_key );

		if ( $temp_data != $serialized_data ) {
			MS_Factory::set_transient(
				$temp_key,
				$serialized_data,
				HOUR_IN_SECONDS
			);

			$this->_api->create_or_update_coupon( $coupon_data );
		}
	}

	/**
	 * Processes purchase action.
	 *
	 * @since 2.0.0
	 * @param MS_Model_Relationship $subscription The related membership relationship.
	 */
	public function process_purchase( $subscription ) {
		do_action(
			'ms_gateway_stripeplan_process_purchase_before',
			$subscription,
			$this
		);
		$this->_api->mode = $this->mode;

		$member = $subscription->get_member();
		$invoice = $subscription->get_current_invoice();

		if ( ! empty( $_POST['stripeToken'] ) ) {
			lib2()->array->strip_slashes( $_POST, 'stripeToken' );

			$token = $_POST['stripeToken'];
			$customer = $this->_api->get_stripe_customer( $member, $token );

			if ( 0 == $invoice->total ) {
				// Free, just process.
				$invoice->changed();
			} else {
				// Get or create the subscription.
				$stripe_sub = $this->_api->subscribe(
					$customer,
					$invoice
				);

				if ( 'active' == $stripe_sub->status ) {
					$invoice->pay_it( $this->id, $stripe_sub->id );

					$this->cancel_if_done( $subscription, $stripe_sub );
				}
			}
		} else {
			throw new Exception( __( 'Stripe gateway token not found.', MS_TEXT_DOMAIN ) );
		}

		return apply_filters(
			'ms_gateway_stripeplan_process_purchase',
			$invoice,
			$this
		);
	}

	/**
	 * Check if the subscription is still active.
	 *
	 * @since 2.0.0
	 * @param MS_Model_Relationship $subscription The related membership relationship.
	 * @return bool True on success.
	 */
	public function request_payment( $subscription ) {
		$was_paid = false;

		do_action(
			'ms_gateway_stripeplan_request_payment_before',
			$subscription,
			$this
		);
		$this->_api->mode = $this->mode;

		$member = $subscription->get_member();
		$invoice = $subscription->get_current_invoice();

		if ( ! $invoice->is_paid() ) {
			try {
				$customer = $this->_api->find_customer( $member );

				if ( ! empty( $customer ) ) {
					if ( 0 == $invoice->total ) {
						$invoice->changed();
					} else {
						// Get or create the subscription.
						$stripe_sub = $this->_api->subscribe(
							$customer,
							$invoice
						);

						if ( 'active' == $stripe_sub->status ) {
							$was_paid = true;
							$invoice->pay_it( $this->id, $stripe_sub->id );

							$this->cancel_if_done( $subscription, $stripe_sub );
						}
					}
				} else {
					MS_Helper_Debug::log( "Stripe customer is empty for user $member->username" );
				}
			} catch ( Exception $e ) {
				MS_Model_Event::save_event( MS_Model_Event::TYPE_PAYMENT_FAILED, $subscription );
				MS_Helper_Debug::log( $e->getMessage() );
			}
		} else {
			// Invoice was already paid earlier.
			$was_paid = true;
		}

		do_action(
			'ms_gateway_stripeplan_request_payment_after',
			$subscription,
			$was_paid,
			$this
		);

		return $was_paid;
	}

	/**
	 * Checks if a subscription has reached the maximum paycycle repetitions.
	 * If the last paycycle was paid then the subscription is cancelled.
	 *
	 * @since  2.0.0
	 * @internal Called by process_purchase() and request_payment()
	 *
	 * @param  MS_Model_Relationship $subscription
	 * @param  Stripe_Subscription $stripe_sub
	 */
	protected function cancel_if_done( $subscription, $stripe_sub ) {
		$membership = $subscription->get_membership();

		if ( $membership->pay_cycle_repetitions < 1 ) {
			return;
		}

		$payments = $subscription->payments;
		if ( count( $payments ) < $membership->pay_cycle_repetitions ) {
			return;
		}

		$stripe_sub->cancel(
			array( 'at_period_end' => true )
		);
	}

	/**
	 * When a member cancels a subscription we need to notify Stripe to also
	 * cancel the Stripe subscription.
	 *
	 * @since 2.0.0
	 * @param MS_Model_Relationship $subscription The membership relationship.
	 */
	public function cancel_membership( $subscription ) {
		parent::cancel_membership( $subscription );

		$customer = $this->_api->find_customer( $subscription->get_member() );
		$membership = $subscription->get_membership();
		$stripe_sub = false;

		if ( $customer ) {
			$stripe_sub = $this->_api->get_subscription(
				$customer,
				$membership
			);
		}

		if ( $stripe_sub ) {
			$stripe_sub->cancel(
				array( 'at_period_end' => true )
			);
		}
	}

	/**
	 * Get Stripe publishable key.
	 *
	 * @since 1.0.0
	 * @api
	 *
	 * @return string The Stripe API publishable key.
	 */
	public function get_publishable_key() {
		$this->_api->mode = $this->mode;
		return $this->_api->get_publishable_key();
	}

	/**
	 * Get Stripe secret key.
	 *
	 * @since 1.0.0
	 * @internal The secret key should not be used outside this object!
	 *
	 * @return string The Stripe API secret key.
	 */
	protected function get_secret_key() {
		$this->_api->mode = $this->mode;
		return $this->_api->get_secret_key();
	}

	/**
	 * Verify required fields.
	 *
	 * @since 1.0.0
	 * @return boolean True if configured.
	 */
	public function is_configured() {
		$key_pub = $this->get_publishable_key();
		$key_sec = $this->get_secret_key();

		$is_configured = ! ( empty( $key_pub ) || empty( $key_sec ) );

		return apply_filters(
			'ms_gateway_stripeplan_is_configured',
			$is_configured
		);
	}

}