<?php
namespace docker {
	function env_value(string ...$names): string {
		foreach ($names as $name) {
			$value = $_ENV[$name] ?? getenv($name);
			if (is_string($value) && $value !== '') {
				return $value;
			}
		}
		return '';
	}

	function html_attr(string $value): string {
		return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	/** Replace an empty auth[field] value="" (either quote style). Leaves a value Adminer already filled. */
	function fill_empty_input(string $html, string $field, string $value): string {
		if ($value === '') {
			return $html;
		}
		$escaped = html_attr($value);
		$quotedField = preg_quote($field, '~');
		$updated = preg_replace_callback(
			'~(<input\b[^>]*\bname=(["\'])auth\[' . $quotedField . '\]\2[^>]*\bvalue=)\2\2~',
			static function (array $matches) use ($escaped): string {
				return $matches[1] . $matches[2] . $escaped . $matches[2];
			},
			$html,
			1,
			$count
		);
		return ($count === 1 && is_string($updated)) ? $updated : $html;
	}

	function fill_password(string $html, string $value): string {
		if ($value === '') {
			return $html;
		}
		$escaped = html_attr($value);
		$updated = preg_replace_callback(
			'~(<input\b(?=[^>]*\btype=(["\'])password\2)(?=[^>]*\bname=(["\'])auth\[password\]\3)[^>]*)>~',
			static function (array $matches) use ($escaped): string {
				if (preg_match('~\bvalue=~', $matches[1]) === 1) {
					return $matches[0];
				}
				return $matches[1] . ' value="' . $escaped . '">';
			},
			$html,
			1,
			$count
		);
		return ($count === 1 && is_string($updated)) ? $updated : $html;
	}

	function fill_driver(string $html, string $driver): string {
		if ($driver === '' || $driver === 'server' || preg_match('/^[A-Za-z0-9_-]+$/', $driver) !== 1) {
			return $html;
		}
		// A driver in the URL (for example ?pgsql) is an explicit choice.
		if (defined('Adminer\\DRIVER') && constant('Adminer\\DRIVER') !== 'server') {
			return $html;
		}
		if (!str_contains($html, ' value="' . $driver . '"')) {
			return $html;
		}
		$html = str_replace(' value="server" selected', ' value="server"', $html);
		$updated = preg_replace(
			'~ value="' . preg_quote($driver, '~') . '"(?! selected)~',
			' value="' . $driver . '" selected',
			$html,
			1
		);
		return is_string($updated) ? $updated : $html;
	}

	function plugin_enabled(string $slug): bool {
		foreach (glob('plugins-enabled/*.php') ?: [] as $path) {
			if (str_contains(basename($path, '.php'), $slug)) {
				return true;
			}
		}
		return false;
	}

	function permanent_checkbox_enabled(): bool {
		$value = strtolower(env_value('ADMINER_PERMANENT_LOGIN'));
		return !in_array($value, ['0', 'false', 'off', 'no'], true);
	}

	function permanent_login_key(): string {
		$key = env_value('ADMINER_PERMANENT_LOGIN_KEY');
		if ($key !== '') {
			return $key;
		}
		$path = '/var/lib/adminer/permanent.key';
		if (!is_readable($path)) {
			return '';
		}
		return trim((string) file_get_contents($path));
	}

	/** Checked by default. The checkbox is printed outside loginFormField(), so a nonce'd script ticks it. */
	function login_extras_script(): string {
		if (!function_exists('Adminer\\script')) {
			return '';
		}
		$parts = [];
		if (permanent_checkbox_enabled()) {
			$parts[] = <<<'JS'
document.addEventListener('DOMContentLoaded',function(){
var box=document.querySelector("input[name='auth[permanent]']");
if(!box)return;
var saved=document.cookie.split('; ').some(function(part){return part.indexOf('adminer_permanent=')===0;});
if(!saved)box.checked=true;
});
JS;
		}
		// The passkey plugin returns the db row itself and never calls later hooks.
		$db = env_value('ADMINER_DEFAULT_DB', 'ADMINER_DEFAULT_DBNAME');
		if ($db !== '') {
			$json = json_encode($db, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
			$parts[] = 'document.addEventListener(\'DOMContentLoaded\',function(){var field=document.querySelector("input[name=\'auth[db]\']");if(field&&field.value==="")field.value=' . $json . ';});';
		}
		if (!$parts) {
			return '';
		}
		return \Adminer\script(implode("\n", $parts));
	}

	function passkey_plugin(): ?object {
		if (!is_readable('plugins/login-passkey.php') || plugin_enabled('login-passkey')) {
			return null;
		}
		require_once 'plugins/login-passkey.php';
		if (!class_exists('AdminerLoginPasskey', false)) {
			return null;
		}
		if (is_readable('/usr/local/share/adminer/docker-login-passkey.php')) {
			require_once '/usr/local/share/adminer/docker-login-passkey.php';
		}
		$file = '/var/lib/adminer/login-passkey.php';
		if (is_readable($file)) {
			$loaded = require $file;
			if ($loaded instanceof \AdminerLoginPasskey) {
				return $loaded;
			}
		}
		if (class_exists('DockerLoginPasskey', false)) {
			return new \DockerLoginPasskey(
				env_value('ADMINER_PASSKEY_ID'),
				env_value('ADMINER_PASSKEY_ACCOUNTS'),
				env_value('ADMINER_PASSKEY_PASSWORD_HASH'),
			);
		}
		return new \AdminerLoginPasskey(
			env_value('ADMINER_PASSKEY_ID'),
			env_value('ADMINER_PASSKEY_ACCOUNTS'),
			env_value('ADMINER_PASSKEY_PASSWORD_HASH'),
		);
	}

	/**
	 * MongoDB and Redis ship as plugin files. php-fpm keeps included files and classes
	 * for the life of the worker, so the implementation is loaded only on the request
	 * that selects that driver, and only once per worker.
	 */
	function register_plugin_drivers(): void {
		static $fullyLoaded = [];
		$drivers = [
			'mongo' => 'plugins/drivers/mongo.php',
			'redis' => 'plugins/drivers/redis.php',
		];
		foreach ($drivers as $id => $file) {
			if (!is_readable($file)) {
				continue;
			}
			if (isset($_GET[$id])) {
				if (empty($fullyLoaded[$id])) {
					require $file;
					$fullyLoaded[$id] = true;
				}
				continue;
			}
			if (!empty($fullyLoaded[$id])) {
				continue;
			}
			$code = (string) file_get_contents($file);
			if (preg_match('/add_driver\(\s*"' . preg_quote($id, '/') . '"\s*,\s*"([^"]*)"\s*\)/', $code, $matches) === 1) {
				\Adminer\add_driver($id, $matches[1]);
			}
		}
	}

	function adminer_object() {
		// class_exists: a php-fpm worker runs this function on every request.
		if (!class_exists(PermanentLoginPlugin::class, false)) {
			/**
			 * Keeps the permanent-login key somewhere that survives a restart.
			 * Adminer's own key file lives in /tmp and disappears with the container.
			 */
			final class PermanentLoginPlugin extends \Adminer\Plugin {
				public function permanentLogin($create = false) {
					$key = permanent_login_key();
					return $key !== '' ? $key : null;
				}
			}
		}

		if (!class_exists(LoginDefaultsPlugin::class, false)) {
			/**
			 * Prefills the login form from ADMINER_DEFAULT_* and ticks Permanent login.
			 * Replaces Adminer's own loginFormField hook, which upstream did for the server field.
			 */
			final class LoginDefaultsPlugin extends \Adminer\Plugin {
				public function __construct(
					private \Adminer\Adminer $adminer
				) { }

				public function loginFormField(...$args): string {
					$field = (function (...$args): string {
						return $this->loginFormField(...$args);
					})->call($this->adminer, ...$args);

					$name = (string) ($args[0] ?? '');
					if ($name === 'server') {
						$server = env_value('ADMINER_DEFAULT_SERVER');
						$field = fill_empty_input($field, 'server', $server !== '' ? $server : 'db');
					} elseif ($name === 'username') {
						$field = fill_empty_input($field, 'username', env_value('ADMINER_DEFAULT_USER', 'ADMINER_DEFAULT_USERNAME'));
					} elseif ($name === 'password') {
						$field = fill_password($field, env_value('ADMINER_DEFAULT_PASSWORD'));
						$field .= login_extras_script();
					} elseif ($name === 'db') {
						$field = fill_empty_input($field, 'db', env_value('ADMINER_DEFAULT_DB', 'ADMINER_DEFAULT_DBNAME'));
					} elseif ($name === 'driver') {
						$field = fill_driver($field, env_value('ADMINER_DEFAULT_DRIVER'));
					}

					return $field;
				}
			}
		}

		register_plugin_drivers();

		$plugins = [];
		// Passkey must be listed before login plugins that can accept a password on their own.
		$passkey = passkey_plugin();
		if ($passkey !== null) {
			$plugins[] = $passkey;
		}

		foreach (glob('plugins-enabled/*.php') ?: [] as $plugin) {
			$plugins[] = require $plugin;
		}

		if (is_readable('plugins/human-readable-size.php') && !plugin_enabled('human-readable-size')) {
			if (!class_exists('AdminerHumanReadableSize', false)) {
				require_once 'plugins/human-readable-size.php';
			}
			$plugins[] = new \AdminerHumanReadableSize();
		}

		$plugins[] = new PermanentLoginPlugin();

		$adminer = new \Adminer\Plugins($plugins);

		(function () {
			$last = &$this->hooks['loginFormField'][\array_key_last($this->hooks['loginFormField'])];
			if ($last instanceof \Adminer\Adminer) {
				$defaults = new LoginDefaultsPlugin($last);
				$this->plugins[] = $defaults;
				$last = $defaults;
			}
		})->call($adminer);

		return $adminer;
	}
}

namespace {
	if (basename($_SERVER['DOCUMENT_URI'] ?? $_SERVER['REQUEST_URI']) === 'adminer.css' && is_readable('adminer.css')) {
		header('Content-Type: text/css');
		readfile('adminer.css');
		exit;
	}

	function adminer_object() {
		return \docker\adminer_object();
	}

	require('adminer.php');
}
