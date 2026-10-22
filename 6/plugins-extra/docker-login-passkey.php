<?php

/**
 * Prefills the database field, then lets the official passkey plugin print its row.
 * AdminerLoginPasskey::loginFormField() returns the db field itself and does not call later plugins.
 */
class DockerLoginPasskey extends AdminerLoginPasskey {
	function loginFormField($name, $heading, $value) {
		if ($name === 'db') {
			$value = \docker\fill_empty_input(
				$value,
				'db',
				\docker\env_value('ADMINER_DEFAULT_DB', 'ADMINER_DEFAULT_DBNAME'),
			);
		}
		return parent::loginFormField($name, $heading, $value);
	}
}
