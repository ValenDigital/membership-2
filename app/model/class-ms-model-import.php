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
 * Base class for all import handlers.
 *
 * @since 1.1.0
 * @package Membership
 * @subpackage Model
 */
class MS_Model_Import extends MS_Model {

	/**
	 * The sanitized import source object. The value of this property is set by
	 * the prepare() function.
	 *
	 * @since 1.1.0
	 *
	 * @var array
	 */
	public $source = array();

	/**
	 * Holds a list of all errors that happen during import.
	 *
	 * @since 1.1.0
	 *
	 * @var array
	 */
	protected $errors = array();

	/**
	 * Holds a list of all imported objects
	 *
	 * @since 1.1.0
	 *
	 * @var array
	 */
	static protected $cache = array();

	/**
	 * This function parses the Import source (e.g. an file-upload or settings
	 * of another plugin) and returns true in case the source data is valid.
	 * When returning true then the  $source property of the model is set to
	 * the sanitized import source data.
	 *
	 * Logic has to be implemented by child classes.
	 *
	 * @since  1.1.0
	 *
	 * @return bool
	 */
	public function prepare() {
		throw new Exception( 'Method to be implemented in child class' );
	}

	/**
	 * Returns true if the specific import-source is present and can be used
	 * for import.
	 *
	 * Must be implemented by the child classes.
	 *
	 * @since  1.1.0
	 * @return bool
	 */
	static public function present() {
		return false;
	}

	/**
	 * Checks if the provided data is a recognized import object.
	 * If not an import object then FALSE will be returned, otherwise the
	 * object itself.
	 *
	 * @since  1.1.0
	 * @param  object $data Import object to test.
	 * @return object|false
	 */
	protected function validate_object( $data ) {
		$data = apply_filters( 'ms_import_validate_object_before', $data );

		if ( empty( $data )
			|| ! is_object( $data )
			|| ! isset( $data->source )
			|| ! isset( $data->plugin_version )
			|| ! isset( $data->export_time )
			|| ! isset( $data->notes )
			|| ! isset( $data->memberships )
			|| ! isset( $data->members )
			|| ! isset( $data->settings )
		) {
			return false;
		} else {
			return apply_filters( 'ms_import_validate_object', $data );
		}
	}

	/**
	 * Sets or returns an import object.
	 *
	 * This is a temporary storage of all objects created during import and
	 * associates these objects with an ID to recognize them again.
	 * If the $obj parameter is left empty then the object will be returned,
	 * otherwise the object will be added to the storage.
	 *
	 * @since  1.1.0
	 * @param  string $type Object type ('memberhip', ...)
	 * @param  string $id Import-ID
	 * @param  any $obj The imported object
	 * @return any Either the ID (when $obj is specified) or the $obj
	 */
	protected function import_obj( $type, $id, $obj = null ) {
		if ( ! isset( self::$cache[$type] ) ) {
			self::$cache[$type] = array();
		}

		if ( null !== $obj ) {
			self::$cache[$type][$id] = $obj;
			return $id;
		} else {
			if ( isset( self::$cache[$type][$id] ) ) {
				return self::$cache[$type][$id];
			} else {
				return null;
			}
		}
	}

	/**
	 * Import data that was previously generated by the preview_object()
	 * function.
	 */
	public function import_data( $data, $args ) {
		$this->errors = array();

		// Make sure the Import object can be parsed.
		$data = $this->validate_object( $data );
		if ( empty( $data ) ) {
			WDev()->message(
				__( 'Import-data could not be parsed. Please try again.', MS_TEXT_DOMAIN ),
				'err'
			);
			return false;
		}

		// Clear current data, if the user wants it.
		if ( $args['clear_all'] ) {
			// Delete all Relationships.
			$relationships = MS_Model_Relationship::get_membership_relationships(
				array( 'status' => 'all' )
			);
			foreach ( $relationships as $relatioship ) {
				$relatioship->delete();
			}

			// Delete all Memberships.
			$memberships = MS_Model_Membership::get_memberships();
			foreach ( $memberships as $membership ) {
				if ( $membership->is_special() ) { continue; }
				$membership->delete( true );
			}
		}

		// Import Memberships.
		foreach ( $data->memberships as $obj ) {
			$this->import_membership( $obj );
		}

		// Import Members.
		foreach ( $data->members as $obj ) {
			$this->import_member( $obj );
		}

		// Import other settings.
		foreach ( $data->settings as $setting => $value ) {
			$this->import_setting( $setting, $value );
		}

		WDev()->message( __( 'Data imported!', MS_TEXT_DOMAIN ) );
	}

	/**
	 * Import specific data: A single membership
	 *
	 * @since  1.1.0
	 * @param  object $obj The import object
	 */
	protected function import_membership( $obj ) {
		$membership = MS_Factory::create( 'MS_Model_Membership' );
		$this->populate_membership( $membership, $obj );
		$membership->save();

		$this->import_obj( 'membership', $obj->id, $membership );
	}

	/**
	 * Makes sure the specified period-type is a recognized value.
	 *
	 * @since  1.1.0
	 * @param  string $period_type An unvalidated period string
	 * @return string A valid period-type string
	 */
	protected function valid_period( $period_type ) {
		$res = 'days';

		if ( strlen( $period_type ) > 0 ) {
			switch ( $period_type[0] ) {
				case 'd': $res = 'days'; break;
				case 'w': $res = 'weeks'; break;
				case 'm': $res = 'months'; break;
				case 'y': $res = 'years'; break;
			}
		}

		return $res;
	}

	/**
	 * Helper function used by import_membership
	 * This is a separate function because it is used to populate normal
	 * memberships and also child memberships
	 *
	 * @since  1.1.0
	 */
	protected function populate_membership( &$membership, $obj ) {
		$membership->name = $obj->name;
		$membership->description = $obj->description;
		$membership->type = $obj->type;
		$membership->active = (bool) $obj->active;
		$membership->private = (bool) $obj->private;
		$membership->is_free = (bool) $obj->free;
		$membership->dripped_type = $obj->dripped;
		$membership->is_setup_complete = true;

		if ( isset( $obj->period_type ) ) {
			$obj->period_type = $this->valid_period( $obj->period_type );
		}
		if ( isset( $obj->trial_period_type ) ) {
			$obj->trial_period_type = $this->valid_period( $obj->trial_period_type );
		}

		if ( isset( $obj->pay_type ) ) {
			$membership->payment_type = $obj->pay_type;
			if ( $membership->payment_type == MS_Model_Membership::PAYMENT_TYPE_FINITE ) {
				$membership->period = array();
				if ( isset( $obj->period_unit ) ) {
					$membership->period['period_unit'] = $obj->period_unit;
				}
				if ( isset( $obj->period_type ) ) {
					$membership->period['period_type'] = $obj->period_type;
				}
			} elseif ( $membership->payment_type == MS_Model_Membership::PAYMENT_TYPE_RECURRING ) {
				$membership->pay_cycle_period = array();
				if ( isset( $obj->period_unit ) ) {
					$membership->pay_cycle_period['period_unit'] = $obj->period_unit;
				}
				if ( isset( $obj->period_type ) ) {
					$membership->pay_cycle_period['period_type'] = $obj->period_type;
				}
			} elseif ( $membership->payment_type == MS_Model_Membership::PAYMENT_TYPE_DATE_RANGE ) {
				if ( isset( $obj->period_start ) ) {
					$membership->period_date_start = $obj->period_start;
				}
				if ( isset( $obj->period_end ) ) {
					$membership->period_date_end = $obj->period_end;
				}
			}
		}

		if ( ! $membership->is_free ) {
			if ( isset( $obj->price ) ) {
				$membership->price = $obj->price;
			}
		}

		if ( isset( $obj->trial ) ) {
			$membership->trial_period_enabled = (bool) $obj->trial;
		}

		if ( $membership->trial_period_enabled ) {
			$membership->trial_period = array();
			if ( isset( $obj->trial_price ) ) {
				$membership->trial_price = $obj->trial_price;
			}
			if ( isset( $obj->trial_period_unit ) ) {
				$membership->trial_period['period_unit'] = $obj->trial_period_unit;
			}
			if ( isset( $obj->trial_period_type ) ) {
				$membership->trial_period['period_type'] = $obj->trial_period_type;
			}
		}

		// We set this last because it might change some other values as well...
		$membership->special = $obj->special;
	}

	/**
	 * Import specific data: A single member
	 *
	 * @since  1.1.0
	 * @param  object $obj The import object
	 */
	protected function import_member( $obj ) {
		$wpuser = get_user_by( 'email', $obj->email );

		if ( $wpuser ) {
			$member = MS_Factory::load( 'MS_Model_Member', $wpuser->ID );
		} else {
			$wpuser = wp_create_user( $obj->username, '', $obj->email );
			if ( is_numeric( $wpuser ) ) {
				$member = MS_Factory::load( 'MS_Model_Member', $wpuser );
			} else {
				$this->errors[] = sprintf(
					__( 'Could not import Member <strong>%1$s</strong> (%2$s)', MS_TEXT_DOMAIN ),
					esc_attr( $obj->username ),
					esc_attr( $obj->email )
				);
				continue;
			}
		}

		// Import the member details.
		$member->is_member = true;
		$member->active = true;

		$pay = $obj->payment;

		// Stripe.
		$gw_stripe = MS_Gateway_Stripe::ID;
		$member->set_gateway_profile( $gw_stripe, 'card_exp', $pay->stripe_card_exp );
		$member->set_gateway_profile( $gw_stripe, 'card_num', $pay->stripe_card_num );
		$member->set_gateway_profile( $gw_stripe, 'customer_id', $pay->stripe_customer );

		// Authorize.
		$gw_auth = MS_Gateway_Authorize::ID;
		$member->set_gateway_profile( $gw_auth, 'card_exp', $pay->authorize_card_exp );
		$member->set_gateway_profile( $gw_auth, 'card_num', $pay->authorize_card_num );
		$member->set_gateway_profile( $gw_auth, 'cim_profile_id', $pay->authorize_cim_profile );
		$member->set_gateway_profile( $gw_auth, 'cim_payment_profile_id', $pay->authorize_cim_payment_profile );

		$member->save();
		$this->import_obj( 'member', $obj->id, $member );

		// Import all memberships of the member
		foreach ( $obj->subscriptions as $registration ) {
			$this->import_registration( $member, $registration );
		}
	}

	/**
	 * Import specific data: A single registration (= relationship)
	 *
	 * @since  1.1.0
	 * @param  object $obj The import object
	 */
	protected function import_registration( $member, $obj ) {
		$membership = $this->import_obj( 'membership', $obj->membership );

		if ( empty( $membership ) ) {
			$this->errors[] = sprintf(
				__( 'Could not import a Membership for User <strong>%1$s</strong> (%2$s)', MS_TEXT_DOMAIN ),
				esc_attr( $obj->username ),
				esc_attr( $obj->email )
			);
			return;
		}

		if ( ! empty( $membership->special ) ) {
			$this->errors[] = sprintf(
				__( 'Did not import the special membership %2$s for <strong>%1$s</strong>', MS_TEXT_DOMAIN ),
				esc_attr( $obj->username ),
				esc_attr( $membership->name )
			);
			return;
		}

		$ms_relationship = $member->add_membership( $membership->id );

		$this->import_obj( 'registration', $obj->id, $ms_relationship );

		// Import invoices for this registration
		foreach ( $obj->invoices as $invoice ) {
			$this->import_invoice( $ms_relationship, $invoice );
		}
	}

	/**
	 * Import specific data: A single invoice
	 *
	 * @since  1.1.0
	 * @param  object $obj The import object
	 */
	protected function import_invoice( $ms_relationship, $obj ) {
		$ms_invoice = MS_Model_Invoice::create_invoice( $ms_relationship );
		$ms_invoice->invoice_number = $obj->invoice_number;
		$ms_invoice->external_id = $obj->external_id;
		$ms_invoice->gateway_id = $obj->gateway;
		$ms_invoice->status = $obj->status;
		$ms_invoice->coupon_id = $obj->coupon;
		$ms_invoice->currency = $obj->currency;
		$ms_invoice->amount = $obj->amount;
		$ms_invoice->discount = $obj->discount;
		$ms_invoice->pro_rate = $obj->discount2;
		$ms_invoice->total = $obj->total;
		$ms_invoice->trial_period = $obj->for_trial;
		$ms_invoice->due_date = $obj->due;
		$ms_invoice->notes = $obj->notes;
		$ms_invoice->taxable = $obj->taxable;

		if ( isset( $obj->tax_rate ) ) {
			$ms_invoice->tax_rate = $obj->tax_rate;
		}
		if ( isset( $obj->tax_name ) ) {
			$ms_invoice->tax_name = $obj->tax_name;
		}

		$this->import_obj( 'invoice', $obj->id, $ms_invoice );

		$ms_invoice->save();
	}

	/**
	 * Import specific data: A single setting
	 *
	 * @since  1.1.0
	 * @param  object $obj The import object
	 */
	protected function import_setting( $setting, $value ) {
		switch ( $setting ) {
			// Import Add-On states.
			case 'addons':
				$model = MS_Factory::load( 'MS_Model_Addon' );
				foreach ( $value as $addon => $state ) {
					if ( $state ) {
						$model->enable( $addon );
					} else {
						$model->disable( $addon );
					}
				}
				$model->save();
				break;
		}
	}
}
