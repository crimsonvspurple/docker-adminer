<?php

/**
 * The compiled Adminer build strips parameter types. The MongoDB and Redis
 * driver sources still have them, and PHP rejects the override. Drop parameter
 * types and keep return types.
 */
if ($argc < 2) {
	fwrite(STDERR, "usage: strip-driver-types.php file...\n");
	exit(1);
}

foreach (array_slice($argv, 1) as $path) {
	if (!is_readable($path)) {
		fwrite(STDERR, "missing $path\n");
		exit(1);
	}
	$tokens = token_get_all(file_get_contents($path));
	$out = '';
	$count = count($tokens);
	for ($i = 0; $i < $count; $i++) {
		$token = $tokens[$i];
		if (!is_array($token) || $token[0] !== T_FUNCTION) {
			$out .= is_array($token) ? $token[1] : $token;
			continue;
		}
		$out .= $token[1];
		$i++;
		while ($i < $count) {
			$current = $tokens[$i];
			$out .= is_array($current) ? $current[1] : $current;
			$i++;
			if ($current === '(') {
				break;
			}
		}
		$depth = 1;
		$param = [];
		$flush = static function () use (&$param, &$out): void {
			$out .= strip_param_types($param);
			$param = [];
		};
		while ($i < $count && $depth > 0) {
			$current = $tokens[$i];
			$text = is_array($current) ? $current[1] : $current;
			if ($text === '(') {
				$depth++;
			}
			if ($text === ')') {
				$depth--;
				if ($depth === 0) {
					$flush();
					$out .= ')';
					break;
				}
			}
			if ($text === ',' && $depth === 1) {
				$flush();
				$out .= ',';
				$i++;
				continue;
			}
			$param[] = $current;
			$i++;
		}
	}
	file_put_contents($path, $out);
}

/** @param list<array{0:int,1:string,2:int}|string> $tokens */
function strip_param_types(array $tokens): string {
	$equals = null;
	foreach ($tokens as $index => $token) {
		if ($token === '=') {
			$equals = $index;
			break;
		}
	}
	$head = $equals === null ? $tokens : array_slice($tokens, 0, $equals);
	$tail = $equals === null ? [] : array_slice($tokens, $equals);
	$start = 0;
	foreach ($head as $index => $token) {
		$id = is_array($token) ? $token[0] : null;
		$text = is_array($token) ? $token[1] : $token;
		if ($id === T_VARIABLE || $text === '&' || $id === T_ELLIPSIS) {
			$start = $index;
			break;
		}
	}
	$kept = array_merge(array_slice($head, $start), $tail);
	$out = '';
	foreach ($kept as $token) {
		$out .= is_array($token) ? $token[1] : $token;
	}
	return $out;
}
