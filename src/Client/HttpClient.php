<?php

declare(strict_types=1);

namespace Shaykhnazar\HikvisionIsapi\Client;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Shaykhnazar\HikvisionIsapi\Client\Contracts\HttpClientInterface;
use Shaykhnazar\HikvisionIsapi\Exceptions\AuthenticationException;
use Shaykhnazar\HikvisionIsapi\Exceptions\DeviceBusyException;
use Shaykhnazar\HikvisionIsapi\Exceptions\DeviceUnreachableException;
use Shaykhnazar\HikvisionIsapi\Exceptions\HikvisionException;
use Shaykhnazar\HikvisionIsapi\Exceptions\InvalidResponseException;

class HttpClient implements HttpClientInterface
{
    private Client $client;

    /**
     * @param  Client|null  $client  Optional pre-configured Guzzle client. Useful for
     *                               injecting custom middleware, retries or a test handler.
     */
    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client;
    }

    public function get(string $uri, #[\SensitiveParameter] array $options = []): array
    {
        return $this->request('GET', $uri, $options);
    }

    /*
     * Request bodies are marked sensitive so PHP redacts them from stack traces.
     *
     * Every write to a terminal carries personal data — a name, a card number, a
     * base64 face — and PHP records call arguments in a trace unless told not
     * to. The setting that tells it not to has a compiled default of 0 and is
     * not set by the official `php:*-alpine` images this ships on, so the
     * default deployment is the leaking one.
     *
     * Marking the entry points was not enough on its own: the assembled payload
     * is passed onward as an ordinary argument, so the frame for *this* call
     * recorded the face even when the caller's own parameter was redacted. This
     * is where the body actually stops.
     */
    public function post(string $uri, #[\SensitiveParameter] array $data = [], #[\SensitiveParameter] array $options = []): array
    {
        $format = $options['_format'] ?? 'json';
        unset($options['_format']);

        if ($format === 'xml') {
            $options['body'] = $this->arrayToXml($data);
        } else {
            $options['json'] = $data;
        }

        return $this->request('POST', $uri, $options);
    }

    public function put(string $uri, #[\SensitiveParameter] array $data = [], #[\SensitiveParameter] array $options = []): array
    {
        $format = $options['_format'] ?? 'json';
        unset($options['_format']);

        if ($format === 'xml') {
            $options['body'] = $this->arrayToXml($data);
        } else {
            $options['json'] = $data;
        }

        return $this->request('PUT', $uri, $options);
    }

    public function delete(string $uri, #[\SensitiveParameter] array $options = []): array
    {
        return $this->request('DELETE', $uri, $options);
    }

    public function postMultipart(string $uri, #[\SensitiveParameter] array $multipart = [], #[\SensitiveParameter] array $options = []): array
    {
        $options['multipart'] = $multipart;

        return $this->request('POST', $uri, $options);
    }

    public function putMultipart(string $uri, #[\SensitiveParameter] array $multipart = [], #[\SensitiveParameter] array $options = []): array
    {
        $options['multipart'] = $multipart;

        return $this->request('PUT', $uri, $options);
    }

    private function request(string $method, string $uri, #[\SensitiveParameter] array $options): array
    {
        try {
            $response = $this->client->request($method, $uri, $options);

            $body = $response->getBody()->getContents();
            $contentType = strtolower($response->getHeaderLine('Content-Type'));
            $status = $response->getStatusCode();

            if ($body === '' && in_array($status, [204, 205], true)) {
                return [];
            }

            // Parse JSON responses
            if (str_contains($contentType, 'application/json')) {
                return $this->jsonToArray($body, $status);
            }

            // Parse XML responses
            if (str_contains($contentType, 'application/xml') || str_contains($contentType, 'text/xml')) {
                return $this->xmlToArray($body, $status);
            }

            // Fallback: return raw body
            return ['raw' => $body];
        } catch (GuzzleException $e) {
            throw $this->classify($e);
        }
    }

    private function jsonToArray(#[\SensitiveParameter] string $body, int $httpStatus): array
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException $exception) {
            throw new InvalidResponseException('Device returned malformed JSON.', previous: $exception, statusCode: $httpStatus, responseBody: $body);
        }

        if (!is_array($decoded)) {
            throw new InvalidResponseException('Device JSON response must be an object or array.', statusCode: $httpStatus, responseBody: $body);
        }

        if (isset($decoded['ResponseStatus']) && is_array($decoded['ResponseStatus'])) {
            $this->validateDeviceStatus($decoded['ResponseStatus'], $httpStatus, $body);
        } elseif (isset($decoded['statusString']) || isset($decoded['subStatusCode'])) {
            $this->validateDeviceStatus($decoded, $httpStatus, $body);
        }

        return $decoded;
    }

    private function validateDeviceStatus(#[\SensitiveParameter] array $status, int $httpStatus, #[\SensitiveParameter] string $body): void
    {
        $code = filter_var($status['statusCode'] ?? null, FILTER_VALIDATE_INT);
        // ISAPI defines 2–6 as failures. 7 means the change requires a reboot;
        // return it to the caller rather than treating an applied write as failed.
        // https://open.hikvision.com/hardware/XMLs/DEVICE_RESPONSE_STATUS.html
        if (!in_array($code, [2, 3, 4, 5, 6], true)) {
            return;
        }

        $message = "Device rejected the request (ISAPI status {$code}).";
        if ($code === 2) {
            throw new DeviceBusyException($message, $code, statusCode: $httpStatus, responseBody: $body);
        }

        throw new HikvisionException($message, $code, statusCode: $httpStatus, responseBody: $body);
    }

    /**
     * Turn a transport failure into the most specific exception we can justify.
     *
     * Classification is deliberately based only on what is certain: whether a
     * response was received at all, and its HTTP status. Hikvision also encodes
     * detail in `subStatusCode` (unsupported capability, storage full, and so
     * on), but those strings vary by model and firmware, so they are not used
     * here until they can be confirmed against real hardware.
     */
    private function classify(GuzzleException $e): HikvisionException
    {
        $message = "HTTP request failed: {$e->getMessage()}";

        if ($e instanceof ConnectException) {
            return new DeviceUnreachableException($message, $e->getCode(), $e);
        }

        $response = $e instanceof RequestException ? $e->getResponse() : null;

        if ($response === null) {
            return new HikvisionException($message, $e->getCode(), $e);
        }

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($body !== '') {
            $message .= "\nResponse: {$body}";
        }

        if ($status === 401) {
            return new AuthenticationException($message, $e->getCode(), $e, $status, $body);
        }

        if ($status === 408 || $status === 429 || $status >= 500) {
            return new DeviceBusyException($message, $e->getCode(), $e, $status, $body);
        }

        return new HikvisionException($message, $e->getCode(), $e, $status, $body);
    }

    /**
     * Convert array to XML string for Hikvision ISAPI
     * Automatically detects root element from array structure
     */
    private function arrayToXml(#[\SensitiveParameter] array $data, ?string $rootElement = null): string
    {
        // Auto-detect root element from array keys
        if ($rootElement === null) {
            // If array has single key, use it as root element
            if (count($data) === 1) {
                $rootElement = array_key_first($data);
                $data = $data[$rootElement];
            } else {
                // Default fallback
                $rootElement = 'UserInfo';
            }
        }

        if (!is_string($rootElement) || !is_array($data)) {
            throw new \InvalidArgumentException('XML payload must contain a named root object.');
        }

        $this->validateXmlName($rootElement);
        $xml = new \SimpleXMLElement("<?xml version='1.0' encoding='UTF-8'?><{$rootElement}/>");
        $data['@attributes'] = array_replace([
            'version' => '2.0',
            'xmlns' => 'http://www.isapi.org/ver20/XMLSchema',
        ], $data['@attributes'] ?? []);

        $this->arrayToXmlRecursive($data, $xml);

        return $xml->asXML();
    }

    /**
     * Recursively convert array to XML
     */
    private function arrayToXmlRecursive(#[\SensitiveParameter] array $data, \SimpleXMLElement $xml): void
    {
        foreach ($data as $key => $value) {
            if ($key === '@attributes' && is_array($value)) {
                foreach ($value as $name => $attribute) {
                    $this->validateXmlName($name);
                    $xml->addAttribute($name, $this->xmlScalar($attribute));
                }

                continue;
            }

            $this->validateXmlName($key);
            // Each list item repeats its field name; numeric array indices are
            // never XML element names (RightPlan and eventType both use lists).
            $items = is_array($value) && $value !== [] && count(array_filter(array_keys($value), 'is_string')) === 0 ? $value : [$value];
            foreach ($items as $item) {
                if (is_array($item)) {
                    $child = $xml->addChild($key);
                    $this->arrayToXmlRecursive($item, $child);
                } else {
                    $xml->addChild($key, htmlspecialchars($this->xmlScalar($item), ENT_XML1 | ENT_QUOTES, 'UTF-8'));
                }
            }
        }
    }

    private function validateXmlName(string|int $name): void
    {
        if (!is_string($name) || !preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/D', $name)) {
            throw new \InvalidArgumentException('XML payload contains an invalid element or attribute name.');
        }
    }

    private function xmlScalar(#[\SensitiveParameter] mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null || is_scalar($value)) {
            return (string) $value;
        }

        throw new \InvalidArgumentException('XML payload values must be scalars or named objects.');
    }

    /**
     * Convert XML string to array
     */
    private function xmlToArray(#[\SensitiveParameter] string $xml, int $httpStatus = 200): array
    {
        // Devices occasionally emit truncated or malformed XML. Collect libxml
        // errors internally so a bad payload never raises a PHP warning.
        $previous = libxml_use_internal_errors(true);

        try {
            $xmlObj = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($xmlObj === false) {
            return ['raw' => $xml];
        }

        $decoded = json_decode(json_encode($xmlObj), true) ?? ['raw' => $xml];
        if ($xmlObj->getName() === 'ResponseStatus') {
            $this->validateDeviceStatus($decoded, $httpStatus, $xml);
        }

        return $decoded;
    }
}
