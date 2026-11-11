<?php

/*
   Simple CSRF helpers shared by admin pages and APIs.
*/
require_once __DIR__ . '/security.php';

if (!function_exists('csrf_get_token')) {
	function csrf_get_token()
	{
		if (session_status() !== PHP_SESSION_ACTIVE) {
			security_start_session();
		}

		if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
			$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
		}

		return $_SESSION['csrf_token'];
	}
}

if (!function_exists('csrf_token')) {
	function csrf_token()
	{
		return csrf_get_token();
	}
}

if (!function_exists('csrf_extract_request_token')) {
	function csrf_extract_request_token()
	{
		$headerToken = '';
		if (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
			$headerToken = (string)$_SERVER['HTTP_X_CSRF_TOKEN'];
		} elseif (function_exists('getallheaders')) {
			$headers = getallheaders();
			if (is_array($headers) && isset($headers['X-CSRF-Token'])) {
				$headerToken = (string)$headers['X-CSRF-Token'];
			}
		}

		if ($headerToken !== '') {
			return $headerToken;
		}

		if (isset($_POST['_csrf'])) {
			return (string)$_POST['_csrf'];
		}

		$rawBody = file_get_contents('php://input');
		if (is_string($rawBody) && trim($rawBody) !== '') {
			$json = json_decode($rawBody, true);
			if (is_array($json)) {
				if (isset($json['_csrf'])) {
					return (string)$json['_csrf'];
				}
				if (isset($json['csrf_token'])) {
					return (string)$json['csrf_token'];
				}
			}
		}

		return '';
	}
}

if (!function_exists('csrf_is_valid')) {
	function csrf_is_valid($requestToken = null)
	{
		if (session_status() !== PHP_SESSION_ACTIVE) {
			security_start_session();
		}

		$sessionToken = (string)($_SESSION['csrf_token'] ?? '');
		$token = is_string($requestToken) ? $requestToken : csrf_extract_request_token();

		if ($sessionToken === '' || $token === '') {
			return false;
		}

		return hash_equals($sessionToken, $token);
	}
}

if (!function_exists('csrf_validate_or_json')) {
	function csrf_validate_or_json()
	{
		if (csrf_is_valid()) {
			return;
		}

		http_response_code(403);
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode([
			'success' => false,
			'message' => 'Sessao expirada ou token invalido. Recarregue a pagina.'
		]);
		exit;
	}
}
