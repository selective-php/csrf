<?php

namespace Odan\Slim\Csrf;

use RuntimeException;
use Slim\Http\Request;
use Slim\Http\Response;
use Slim\Http\Stream;

/**
 * CSRF protection middleware
 */
final class CsrfMiddleware
{
    /**
     * @var string
     */
    private $name = '__token';

    /**
     * @var string
     */
    private $sessionId;

    /**
     * @var string
     */
    private $salt = '';

    /**
     * @var string
     */
    private $token;

    /**
     * @var bool
     */
    private $protectForms = true;

    /**
     * @var bool
     */
    private $protectJqueryAjax = true;

    /**
     * Constructor
     *
     * @param string $sessionId
     */
    public function __construct(string $sessionId)
    {
        $this->setSessionId($sessionId);
    }

    /**
     * Set session id.
     *
     * @param string $sessionId
     */
    public function setSessionId(string $sessionId)
    {
        if (empty($sessionId)) {
            throw new RuntimeException('CSRF middleware failed. SessionId not found!');
        }

        $this->sessionId = $sessionId;
    }

    /**
     * Set salt.
     *
     * @param string $salt
     */
    public function setSalt(string $salt)
    {
        $this->salt = $salt;
    }

    /**
     * Set token manually.
     *
     * @param string $token
     */
    public function setToken(string $token)
    {
        $this->token = $token;
    }

    /**
     * Set token name.
     *
     * @param string $name
     */
    public function setTokenName(string $name)
    {
        $this->name = $name;
    }

    /**
     * Enable automatic form protection.
     *
     * @param bool $enabled
     */
    public function protectForms(bool $enabled)
    {
        $this->protectForms = $enabled;
    }

    /**
     * Enable automatic jQuery ajax requests.
     *
     * @param bool $enabled
     */
    public function protectJqueryAjax(bool $enabled)
    {
        $this->protectJqueryAjax = $enabled;
    }

    /**
     * Invoke
     *
     * @param Request $request
     * @param Response $response
     * @param $next
     * @return Response
     */
    public function __invoke(Request $request, Response $response, $next): Response
    {
        $tokenValue = $this->getToken();

        $this->validate($request, $tokenValue);

        // Attach Request Attributes
        $request = $request->withAttribute('csrf_token', $tokenValue);

        /* @var Response $response */
        $response = $next($request, $response);

        return $this->injectTokenToResponse($response, $tokenValue);
    }

    /**
     * Get CSRF token
     *
     * @return string
     */
    public function getToken()
    {
        if (!empty($this->token)) {
            return $this->token;
        }
        return hash('sha256', $this->sessionId . $this->salt);
    }

    /**
     * Validate token.
     *
     * @param Request $request
     * @param string $tokenValue
     * @return bool Status
     * @throws RuntimeException If invalid token is given
     */
    public function validate(Request $request, string $tokenValue)
    {
        // Validate POST, PUT, DELETE, PATCH requests
        $method = $request->getMethod();
        if (!in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'])) {
            return true;
        }

        $postData = $request->getParsedBody();

        $requestCsrfToken = null;
        if (isset($postData[$this->name])) {
            $requestCsrfToken = $postData[$this->name];
        }
        if (!$requestCsrfToken) {
            // Reads the value and adds it to the request as the X-XSRF-TOKEN header.
            $headers = $request->getHeader('X-CSRF-Token');
            $requestCsrfToken = reset($headers);
        }

        if ($requestCsrfToken !== $tokenValue) {
            throw new RuntimeException('CSRF middleware failed. Invalid CSRF token. This looks like a cross-site request forgery.');
        }

        return true;
    }

    /**
     * Inject token to response object.
     *
     * @param Response $response
     * @param $tokenValue
     * @return Response|static
     */
    private function injectTokenToResponse(Response $response, $tokenValue)
    {
        // Check if response is html
        $contenTypes = $response->getHeader('content-type');
        $contenType = reset($contenTypes);
        if (strpos($contenType, 'text/html') === false) {
            return $response;
        }

        $body = $response->getBody()->__toString();

        if ($this->protectForms) {
            $body = $this->injectFormHiddenFieldToResponse($body, $tokenValue);
        }

        if ($this->protectJqueryAjax) {
            $body = $this->injectJqueryToResponse($body, $tokenValue);
        }

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $body);
        rewind($stream);

        return $response->withBody(new Stream($stream));
    }

    /**
     * Inject hidden field.
     *
     * @param string $body
     * @param string $tokenValue
     * @return null|string|string[]
     */
    public function injectFormHiddenFieldToResponse(string $body, string $tokenValue)
    {
        $regex = '/(<form\b[^>]*>)(.*?)(<\/form>)/is';
        $htmlHiddenField = sprintf('$1<input type="hidden" name="%s" value="%s">$2$3', $this->name, $tokenValue);
        $body = preg_replace($regex, $htmlHiddenField, $body);

        return $body;
    }

    /**
     * Inject jquery code.
     *
     * @param string $body
     * @param string $tokenValue
     * @return string
     */
    public function injectJqueryToResponse(string $body, string $tokenValue)
    {
        $regex = '/(.*?)(<\/body>)/is';
        $jQueryCode = sprintf('<script>$.ajaxSetup({beforeSend: function (xhr) { xhr.setRequestHeader("X-CSRF-Token","%s"); }});</script>', $tokenValue);
        $body = (string)preg_replace($regex, '$1' . $jQueryCode . '$2', $body, -1, $count);

        if (!$count) {
            // Inject JS code anyway
            $body = $body . $jQueryCode;
        }

        return $body;
    }
}
