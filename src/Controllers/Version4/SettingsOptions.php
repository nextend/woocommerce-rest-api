<?php
/**
 * REST API Setting Options controller
 *
 * Handles requests to the /settings/$group/$setting endpoints.
 *
 * @package Automattic/WooCommerce/RestApi
 */

namespace Automattic\WooCommerce\RestApi\Controllers\Version4;

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\RestApi\Controllers\Version4\Utilities\SettingsTrait;

/**
 * REST API Setting Options controller class.
 */
class SettingsOptions extends AbstractController {
	use SettingsTrait;

	/**
	 * Permission to check.
	 *
	 * @var string
	 */
	protected $resource_type = 'settings';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'settings/(?P<group_id>[\w-]+)';

	/**
	 * Register routes.
	 *
	 * @since 3.0.0
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'args'   => array(
					'group' => array(
						'description' => __( 'Settings group ID.', 'woocommerce-rest-api' ),
						'type'        => 'string',
					),
				),
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			),
			true
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/batch',
			array(
				'args'   => array(
					'group' => array(
						'description' => __( 'Settings group ID.', 'woocommerce-rest-api' ),
						'type'        => 'string',
					),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'batch_items' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( \WP_REST_Server::EDITABLE ),
				),
				'schema' => array( $this, 'get_public_batch_schema' ),
			),
			true
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\w-]+)',
			array(
				'args'   => array(
					'group' => array(
						'description' => __( 'Settings group ID.', 'woocommerce-rest-api' ),
						'type'        => 'string',
					),
					'id'    => array(
						'description' => __( 'Unique identifier for the resource.', 'woocommerce-rest-api' ),
						'type'        => 'string',
					),
				),
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( \WP_REST_Server::EDITABLE ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			),
			true
		);
	}

	/**
	 * Return a single setting.
	 *
	 * @since  3.0.0
	 * @param  \WP_REST_Request $request Request data.
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function get_item( $request ) {
		$setting = $this->get_setting( $request['group_id'], $request['id'] );

		if ( is_wp_error( $setting ) ) {
			return $setting;
		}

		$response = $this->prepare_item_for_response( $setting, $request );

		return rest_ensure_response( $response );
	}

	/**
	 * Return all settings in a group.
	 *
	 * @since  3.0.0
	 * @param  \WP_REST_Request $request Request data.
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function get_items( $request ) {
		$settings = $this->get_group_settings( $request['group_id'] );

		if ( is_wp_error( $settings ) ) {
			return $settings;
		}

		$data = array();

		foreach ( $settings as $setting_obj ) {
			$setting = $this->prepare_item_for_response( $setting_obj, $request );
			$setting = $this->prepare_response_for_collection( $setting );
			if ( $this->is_setting_type_valid( $setting['type'] ) ) {
				$data[] = $setting;
			}
		}

		return rest_ensure_response( $data );
	}

	/**
	 * Get all settings in a group.
	 *
	 * @param string $group_id Group ID.
	 * @return array|\WP_Error
	 */
	public function get_group_settings( $group_id ) {
		if ( empty( $group_id ) ) {
			return new \WP_Error( 'rest_setting_setting_group_invalid', __( 'Invalid setting group.', 'woocommerce-rest-api' ), array( 'status' => 404 ) );
		}

		$settings = apply_filters( 'woocommerce_settings-' . $group_id, array() ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores

		if ( empty( $settings ) ) {
			return new \WP_Error( 'rest_setting_setting_group_invalid', __( 'Invalid setting group.', 'woocommerce-rest-api' ), array( 'status' => 404 ) );
		}

		$filtered_settings = array();
		foreach ( $settings as $setting ) {
			$option_key = $setting['option_key'];
			$setting    = $this->filter_setting( $setting );
			$default    = isset( $setting['default'] ) ? $setting['default'] : '';
			// Get the option value.
			if ( is_array( $option_key ) ) {
				$option           = get_option( $option_key[0] );
				$setting['value'] = isset( $option[ $option_key[1] ] ) ? $option[ $option_key[1] ] : $default;
			} else {
				$admin_setting_value = \WC_Admin_Settings::get_option( $option_key, $default );
				$setting['value']    = $admin_setting_value;
			}

			if ( 'multi_select_countries' === $setting['type'] ) {
				$setting['options'] = WC()->countries->get_countries();
				$setting['type']    = 'multiselect';
			} elseif ( 'single_select_country' === $setting['type'] ) {
				$setting['type']    = 'select';
				$setting['options'] = $this->get_countries_and_states();
			} elseif ( 'single_select_page' === $setting['type'] ) {
				$pages   = get_pages(
					array(
						'sort_column'  => 'menu_order',
						'sort_order'   => 'ASC',
						'hierarchical' => 0,
					)
				);
				$options = array();
				foreach ( $pages as $page ) {
					$options[ $page->ID ] = ! empty( $page->post_title ) ? $page->post_title : '#' . $page->ID;
				}
				$setting['type']    = 'select';
				$setting['options'] = $options;
			}

			$filtered_settings[] = $setting;
		}

		return $filtered_settings;
	}

	/**
	 * Returns a list of countries and states for use in the base location setting.
	 *
	 * @since  3.0.7
	 * @return array Array of states and countries.
	 */
	private function get_countries_and_states() {
		$countries = WC()->countries->get_countries();
		if ( empty( $countries ) ) {
			return array();
		}
		$output = array();
		foreach ( $countries as $key => $value ) {
			$states = WC()->countries->get_states( $key );

			if ( $states ) {
				foreach ( $states as $state_key => $state_value ) {
					$output[ $key . ':' . $state_key ] = $value . ' - ' . $state_value;
				}
			} else {
				$output[ $key ] = $value;
			}
		}
		return $output;
	}

	/**
	 * Get setting data.
	 *
	 * @since  3.0.0
	 * @param string $group_id Group ID.
	 * @param string $setting_id Setting ID.
	 * @return stdClass|\WP_Error
	 */
	public function get_setting( $group_id, $setting_id ) {
		if ( empty( $setting_id ) ) {
			return new \WP_Error( 'rest_setting_setting_invalid', __( 'Invalid setting.', 'woocommerce-rest-api' ), array( 'status' => 404 ) );
		}

		$settings = $this->get_group_settings( $group_id );

		if ( is_wp_error( $settings ) ) {
			return $settings;
		}

		$array_key = array_keys( wp_list_pluck( $settings, 'id' ), $setting_id );

		if ( empty( $array_key ) ) {
			return new \WP_Error( 'rest_setting_setting_invalid', __( 'Invalid setting.', 'woocommerce-rest-api' ), array( 'status' => 404 ) );
		}

		$setting = $settings[ $array_key[0] ];

		if ( ! $this->is_setting_type_valid( $setting['type'] ) ) {
			return new \WP_Error( 'rest_setting_setting_invalid', __( 'Invalid setting.', 'woocommerce-rest-api' ), array( 'status' => 404 ) );
		}

		if ( is_wp_error( $setting ) ) {
			return $setting;
		}

		$setting['group_id'] = $group_id;

		return $setting;
	}

	/**
	 * Get batch of items from requst.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @param string           $batch_type Batch type; one of create, update, delete.
	 * @return array
	 */
	protected function get_batch_of_items_from_request( $request, $batch_type ) {
		$params = $request->get_params();

		if ( ! isset( $params[ $batch_type ] ) ) {
			return array();
		}

		/**
		 * Since our batch settings update is group-specific and matches based on the route,
		 * we inject the URL parameters (containing group) into the batch items
		 */
		$items = array_filter( $params[ $batch_type ] );

		if ( 'update' === $batch_type ) {
			foreach ( $items as $key => $item ) {
				$items[ $key ] = array_merge( $request->get_url_params(), $item );
			}
		}

		return array_filter( $items );
	}

	/**
	 * Update a single setting in a group.
	 *
	 * @since  3.0.0
	 * @param  \WP_REST_Request $request Request data.
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function update_item( $request ) {
		$setting = $this->get_setting( $request['group_id'], $request['id'] );

		if ( is_wp_error( $setting ) ) {
			return $setting;
		}

		if ( is_callable( array( $this, 'validate_setting_' . $setting['type'] . '_field' ) ) ) {
			$value = $this->{'validate_setting_' . $setting['type'] . '_field'}( $request['value'], $setting );
		} else {
			$value = $this->validate_setting_text_field( $request['value'], $setting );
		}

		if ( is_wp_error( $value ) ) {
			return $value;
		}

		if ( is_array( $setting['option_key'] ) ) {
			$setting['value']       = $value;
			$option_key             = $setting['option_key'];
			$prev                   = get_option( $option_key[0] );
			$prev[ $option_key[1] ] = $request['value'];
			update_option( $option_key[0], $prev );
		} else {
			$update_data                           = array();
			$update_data[ $setting['option_key'] ] = $value;
			$setting['value']                      = $value;
			\WC_Admin_Settings::save_fields( array( $setting ), $update_data );
		}

		$response = $this->prepare_item_for_response( $setting, $request );

		return rest_ensure_response( $response );
	}

	/**
	 * Get data for this object in the format of this endpoint's schema.
	 *
	 * @param \WC_Shipping_Method $object Object to prepare.
	 * @param \WP_REST_Request    $request Request object.
	 * @return array Array of data in the correct format.
	 */
	protected function get_data_for_response( $object, $request ) {
		unset( $object['option_key'] );
		return $this->filter_setting( $object );
	}

	/**
	 * Prepare links for the request.
	 *
	 * @param mixed            $item Object to prepare.
	 * @param \WP_REST_Request $request Request object.
	 * @return array
	 */
	protected function prepare_links( $item, $request ) {
		$base  = str_replace( '(?P<group_id>[\w-]+)', $request['group_id'], $this->rest_base );
		$links = array(
			'self'       => array(
				'href' => rest_url( sprintf( '/%s/%s/%s', $this->namespace, $base, $item['id'] ) ),
			),
			'collection' => array(
				'href' => rest_url( sprintf( '/%s/%s', $this->namespace, $base ) ),
			),
		);

		return $links;
	}

	/**
	 * Filters out bad values from the settings array/filter so we
	 * only return known values via the API.
	 *
	 * @since 3.0.0
	 * @param  array $setting Settings.
	 * @return array
	 */
	public function filter_setting( $setting ) {
		$setting = array_intersect_key(
			$setting,
			array_flip( array_filter( array_keys( $setting ), array( $this, 'allowed_setting_keys' ) ) )
		);

		if ( empty( $setting['options'] ) ) {
			unset( $setting['options'] );
		}

		if ( 'image_width' === $setting['type'] ) {
			$setting = $this->cast_image_width( $setting );
		}

		return $setting;
	}

	/**
	 * For image_width, Crop can return "0" instead of false -- so we want
	 * to make sure we return these consistently the same we accept them.
	 *
	 * @todo remove in 4.0
	 * @since 3.0.0
	 * @param  array $setting Settings.
	 * @return array
	 */
	public function cast_image_width( $setting ) {
		foreach ( array( 'default', 'value' ) as $key ) {
			if ( isset( $setting[ $key ] ) ) {
				$setting[ $key ]['width']  = intval( $setting[ $key ]['width'] );
				$setting[ $key ]['height'] = intval( $setting[ $key ]['height'] );
				$setting[ $key ]['crop']   = (bool) $setting[ $key ]['crop'];
			}
		}
		return $setting;
	}

	/**
	 * Callback for allowed keys for each setting response.
	 *
	 * @param  string $key Key to check.
	 * @return boolean
	 */
	public function allowed_setting_keys( $key ) {
		return in_array(
			$key, array(
				'id',
				'group_id',
				'label',
				'description',
				'default',
				'tip',
				'placeholder',
				'type',
				'options',
				'value',
				'option_key',
			), true
		);
	}

	/**
	 * Boolean for if a setting type is a valid supported setting type.
	 *
	 * @since  3.0.0
	 * @param  string $type Type.
	 * @return bool
	 */
	public function is_setting_type_valid( $type ) {
		return in_array(
			$type, array(
				'text',         // Validates with validate_setting_text_field.
				'email',        // Validates with validate_setting_text_field.
				'number',       // Validates with validate_setting_text_field.
				'color',        // Validates with validate_setting_text_field.
				'password',     // Validates with validate_setting_text_field.
				'textarea',     // Validates with validate_setting_textarea_field.
				'select',       // Validates with validate_setting_select_field.
				'multiselect',  // Validates with validate_setting_multiselect_field.
				'radio',        // Validates with validate_setting_radio_field (-> validate_setting_select_field).
				'checkbox',     // Validates with validate_setting_checkbox_field.
				'image_width',  // Validates with validate_setting_image_width_field.
				'thumbnail_cropping', // Validates with validate_setting_text_field.
			)
		);
	}

	/**
	 * Get the settings schema, conforming to JSON Schema.
	 *
	 * @return array
	 */
	public function get_item_schema() {
		$schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'setting',
			'type'       => 'object',
			'properties' => array(
				'id'          => array(
					'description' => __( 'A unique identifier for the setting.', 'woocommerce-rest-api' ),
					'type'        => 'string',
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_title',
					),
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'group_id'    => array(
					'description' => __( 'An identifier for the group this setting belongs to.', 'woocommerce-rest-api' ),
					'type'        => 'string',
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_title',
					),
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'label'       => array(
					'description' => __( 'A human readable label for the setting used in interfaces.', 'woocommerce-rest-api' ),
					'type'        => 'string',
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'description' => array(
					'description' => __( 'A human readable description for the setting used in interfaces.', 'woocommerce-rest-api' ),
					'type'        => 'string',
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'value'       => array(
					'description' => __( 'Setting value.', 'woocommerce-rest-api' ),
					'type'        => 'mixed',
					'context'     => array( 'view', 'edit' ),
				),
				'default'     => array(
					'description' => __( 'Default value for the setting.', 'woocommerce-rest-api' ),
					'type'        => 'mixed',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'tip'         => array(
					'description' => __( 'Additional help text shown to the user about the setting.', 'woocommerce-rest-api' ),
					'type'        => 'string',
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'placeholder' => array(
					'description' => __( 'Placeholder text to be displayed in text inputs.', 'woocommerce-rest-api' ),
					'type'        => 'string',
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'type'        => array(
					'description' => __( 'Type of setting.', 'woocommerce-rest-api' ),
					'type'        => 'string',
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
					'context'     => array( 'view', 'edit' ),
					'enum'        => array( 'text', 'email', 'number', 'color', 'password', 'textarea', 'select', 'multiselect', 'radio', 'image_width', 'checkbox' ),
					'readonly'    => true,
				),
				'options'     => array(
					'description' => __( 'Array of options (key value pairs) for inputs such as select, multiselect, and radio buttons.', 'woocommerce-rest-api' ),
					'type'        => 'object',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
			),
		);

		return $this->add_additional_fields_schema( $schema );
	}
}
