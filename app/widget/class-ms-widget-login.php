<?php

class MS_Widget_Login extends WP_Widget {

	/**
	 * Constructor.
	 * Sets up the widgets name etc.
	 *
	 * @since 1.1.0
	 */
	public function __construct() {
		parent::__construct(
			'ms_widget_login',
			__( '[Protected Content] Login', MS_TEXT_DOMAIN ),
			array(
				'description' => __( 'Display a Login Form to all guests. Logged-in users will see a Logout link.', MS_TEXT_DOMAIN ),
			)
		);
	}

	/**
	 * Outputs the content of the widget.
	 *
	 * @since 1.1.0
	 *
	 * @param array $args
	 * @param array $instance
	 */
	public function widget( $args, $instance ) {
		$redirect_login = false;
		$redirect_logout = false;

		if ( ! empty( $instance['redirect_login'] ) ) {
			$redirect_login = lib2()->net->expand_url( $instance['redirect_login'] );
		}

		if ( ! empty( $instance['redirect_logout'] ) ) {
			$redirect_logout = lib2()->net->expand_url( $instance['redirect_logout'] );
		}

		echo $args['before_widget'];

		if ( ! empty( $instance['title'] ) ) {
			echo $args['before_title'];
			echo apply_filters( 'widget_title', $instance['title'] );
			echo $args['after_title'];
		}

		$scode = sprintf(
			'[%1$s header="no" %2$s %3$s]',
			MS_Helper_Shortcode::SCODE_LOGIN,
			$redirect_login ? 'redirect_login="' . $redirect_login . '"' : '',
			$redirect_logout ? 'redirect_logout="' . $redirect_logout . '"' : ''
		);
		echo do_shortcode( $scode );

		echo $args['after_widget'];
	}

	/**
	 * Outputs the options form on admin
	 *
	 * @since 1.1.0
	 *
	 * @param array $instance The widget options
	 */
	public function form( $instance ) {
		$title = __( 'Login', MS_TEXT_DOMAIN );
		$redirect_login = '';
		$redirect_logout = '';

		if ( ! empty( $instance['title'] ) ) {
			$title = $instance['title'];
		}

		if ( ! empty( $instance['redirect_login'] ) ) {
			$redirect_login = $instance['redirect_login'];
		}

		if ( ! empty( $instance['redirect_logout'] ) ) {
			$redirect_logout = $instance['redirect_logout'];
		}

		$field_title = array(
			'id' => $this->get_field_id( 'title' ),
			'name' => $this->get_field_name( 'title' ),
			'type' => MS_Helper_Html::INPUT_TYPE_TEXT,
			'title' => __( 'Title', MS_TEXT_DOMAIN ),
			'value' => $title,
			'class' => 'widefat',
		);

		$field_redirect_login = array(
			'id' => $this->get_field_id( 'redirect_login' ),
			'name' => $this->get_field_name( 'redirect_login' ),
			'type' => MS_Helper_Html::INPUT_TYPE_TEXT,
			'title' => __( 'Show this page after login', MS_TEXT_DOMAIN ),
			'value' => $redirect_login,
			'placeholder' => MS_Model_Pages::get_url_after_login(),
			'class' => 'widefat',
		);

		$field_redirect_logout = array(
			'id' => $this->get_field_id( 'redirect_logout' ),
			'name' => $this->get_field_name( 'redirect_logout' ),
			'type' => MS_Helper_Html::INPUT_TYPE_TEXT,
			'title' => __( 'Show this page after logout', MS_TEXT_DOMAIN ),
			'value' => $redirect_logout,
			'placeholder' => MS_Model_Pages::get_url_after_logout(),
			'class' => 'widefat',
		);

		MS_Helper_Html::html_element( $field_title );
		MS_Helper_Html::html_element( $field_redirect_login );
		MS_Helper_Html::html_element( $field_redirect_logout );
	}

	/**
	 * Processing widget options on save
	 *
	 * @since 1.1.0
	 *
	 * @param array $new_instance The new options
	 * @param array $old_instance The previous options
	 * @return array Updated safe values to be saved.
	 */
	public function update( $new_instance, $old_instance ) {
		$instance = array();
		$instance['title'] = '';
		$instance['redirect_login'] = '';
		$instance['redirect_logout'] = '';

		if ( ! empty( $new_instance['title'] ) ) {
			$instance['title'] = strip_tags( $new_instance['title'] );
		}

		if ( ! empty( $new_instance['redirect_login'] ) ) {
			$instance['redirect_login'] = strip_tags( $new_instance['redirect_login'] );
		}

		if ( ! empty( $new_instance['redirect_logout'] ) ) {
			$instance['redirect_logout'] = strip_tags( $new_instance['redirect_logout'] );
		}

		return $instance;
	}
}