<?php declare(strict_types=1);
namespace Selfmadeking\BehatApiExtension\Context;

use App\Kernel;
use Doctrine\ORM\EntityManagerInterface;
use Selfmadeking\BehatApiExtension\ArrayContainsComparator\Matcher\JWT as JwtMatcher;
use Selfmadeking\BehatApiExtension\ArrayContainsComparator;
use Selfmadeking\BehatApiExtension\Exception\ArrayContainsComparatorException;
use Selfmadeking\BehatApiExtension\Exception\AssertionFailedException;
use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\UriResolver;
use Assert\AssertionFailedException as AssertionFailure;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Psr\Http\Message\UriInterface;
use InvalidArgumentException;
use RuntimeException;
use stdClass;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;

/**
 * Behat feature context that can be used to simplify testing of JSON-based RESTful HTTP APIs
 */
class ApiContext extends WebTestCase implements ApiClientAwareContext, ArrayContainsComparatorAwareContext {
    /**
     * Request instance
     *
     * @var Request
     */
    protected $request;

    /**
     * Response instance
     *
     * The response object will be set once the request has been made.
     *
     * @var Response
     */
    protected $response;

    /**
     * Base url
     *
     * @var string
     */
    protected $baseUrl;

    /**
     * @var string
     */
    protected $requestPath;

    /**
     * @var KernelBrowser
     */
    private $client;

    /**
     * @var ArrayContainsComparator
     */
    protected $arrayContainsComparator;

    /**
     * @var EntityManagerInterface $em
     */
    protected $em;

    public function __construct(
        ArrayContainsComparator $comparator,
        EntityManagerInterface $em,
        string $baseUrl
    ) {
        $this->arrayContainsComparator = $comparator;
        $this->em = $em;
        $this->baseUrl = $baseUrl;
        parent::__construct();
    }

    /**
     * @return Request
     */
    public function getCurrentRequest(): Request
    {
        return $this->request ?? new Request(
                'GET',
                '',
                [
                    'CONTENT_TYPE' => 'application/json',
                ]
            );
    }

    /**
     * @return Response
     */
    public function getCurrentResponse(): Response
    {
        $response = $this->response;

        if (!$response) {
            throw new RuntimeException(
                'called getCurrentResponse, before response was received'
            );
        }

        return $this->response;
    }

    /**
     * @return array
     */
    public function getDecodedResponseBody(): array
    {
        return $this->jsonDecode(
            $this->getCurrentResponse()
                ->getContent()
        );
    }

    /**
     *
     *
     * @param string $value
     *
     * @return void
     */
    public function setServerHttpAuthorization(string $value): void
    {
        $this->client->setServerParameter(
            'HTTP_Authorization',
            $value
        );
    }

    /**
     * Adds Token to Authentication header for next request.
     *
     * @param string $username
     * @param string $password
     *
     * @Given /^I am successfully logged in with username: "([^"]*)", password: "([^"]*)"$/
     */
    public function iAmSuccessfullyLoggedInWithUsernamePassword(
        string $username,
        string $password
    ): void {
        $content = sprintf(
            '{"username": "%s", "password": "%s"}',
            $username,
            $password
        );

        $prevBody = $this->getCurrentRequest()
            ->getBody()
        ;

        $this->theRequestBodyIs(
            new PyStringNode(
                [$content],
                0
            )
        );
        $this->sendRequest(
            '/api/v2/token',
            'POST'
        );

        $this->setServerHttpAuthorization(
            sprintf(
                'Bearer %s',
                $this->getDecodedResponseBody()['token']
            )
        );

        // set previous body from other step
        $this->request = $this->getCurrentRequest()
            ->withBody(
                $prevBody
            )
        ;
    }

    /**
     * Authenticates with a default patient
     *
     * @Given I am authenticated with default patient
     */
    public function iAmAuthenticatedWithDefaultPatient(): void
    {
        $this->iAmSuccessfullyLoggedInWithUsernamePassword(
            'patient@test.com',
            'rudeModz1@'
        );
    }

    /**
     * Authenticates as a new user
     *
     * @Given I am authenticated with new patient
     */
    public function iAmAuthenticateWithNewPatient(): void
    {
        $this->iAmSuccessfullyLoggedInWithUsernamePassword(
            'new_patient@test.com',
            'rudeModz1@'
        );
    }

    /**
     * Authenticates with a default doctor
     *
     * @Given I am authenticated with default doctor
     */
    public function iAmAuthenticatedWithDefaultDoctor(): void
    {
        $this->iAmSuccessfullyLoggedInWithUsernamePassword(
            'doctor@test.com',
            'rudeModz1@'
        );
    }

    /**
     * Authenticates with a default clinic token
     *
     * @Given I am authenticated with default clinic token
     */
    public function iAmAuthenticatedWithDefaultClinicToken(): void
    {
        $clinic = $this->em->getRepository(Clinic::class)
            ->find('0218b40a-4c5b-40a5-8e63-5f098f8a7ff2')
        ;

        if (!$clinic) {
            self::fail('Default clinic not found');
        }

        $token = $this->clinicApiOptionGenerator->generateClinicToken($clinic);

        $clinic->setApiToken($token);
        $this->em->flush();

        $this->setServerHttpAuthorization(
            sprintf(
                'Bearer %s',
                $token
            )
        );


    }

    /**
     * Set the request body and headers content-type to application/json
     *
     * @param PyStringNode $string The content to set as the request body
     *
     * @return self
     *
     * @Given the request body is:
     */
    public function theRequestBodyIs(PyStringNode $string): self
    {
        $this->request = $this->getCurrentRequest()
            ->withBody(
                Psr7\stream_for($string)
            )
        ;

        return $this;
    }

    /**
     * Set a HTTP request header
     *
     * @param string $header The header name
     * @param string $value  The header value
     *
     * @return self
     *
     * @Given the :header request header is :value
     */
    public function setRequestHeader(
        string $header,
        string $value
    ): self {
        $this->request = $this->getCurrentRequest()
            ->withHeader($header, $value)
        ;

        return $this;
    }

    /**
     * Update the HTTP method of the request
     *
     * @param string $method The HTTP method
     *
     * @return self
     */
    protected function setRequestMethod(string $method): self
    {
        $this->request = $this->getCurrentRequest()
            ->withMethod($method)
        ;

        return $this;
    }

    /**
     * Update path for the request
     *
     * @param string $path
     *
     * @return self
     */
    protected function setRequestPath(string $path): self
    {
        $this->requestPath = $path;

        return $this;
    }

    /**
     * @return string
     */
    public function getRequestPath(): string
    {
        return $this->requestPath;
    }

    /**
     * Get request url
     *
     * @return string
     */
    protected function getRequestUrl(): string
    {
        return $this->baseUrl . $this->getRequestPath();
    }

    /**
     * Request a path
     *
     * @param string $path   The path to request
     * @param string $method The HTTP method to use
     *
     * @return Crawler
     *
     * @When I request :path using HTTP :method
     */
    public function sendRequest(
        string $path,
        string $method
    ): Crawler {
        $this->setRequestMethod($method);
        $this->setRequestPath($path);

        $request = $this->getCurrentRequest();

        $crawler = $this->client->request(
            $request->getMethod(),
            $this->getRequestUrl(),
            [],
            [],
            $request->getHeaders(),
            $request->getBody()
                ->getContents()
        );

        $this->response = $this->client->getResponse();

        return $crawler;
    }

    /**
     * Require a response object
     *
     * @throws RuntimeException
     */
    protected function requireResponse(): void
    {
        if (!$this->response) {
            throw new RuntimeException(
                'The request has not been made yet, so no response object exists.'
            );
        }
    }

    /**
     * Assert the HTTP response code
     *
     * @param string $code The HTTP response code
     *
     * @return void
     *
     * @Then the response code is :code
     */
    public function assertResponseCodeIs(string $code): void
    {
        $this->requireResponse();
        $expected = (int)$code;
        $this->validateResponseCode($expected);

        $actual = $this->getCurrentResponse()->getStatusCode();

        try {
            $this->assertSame(
                $expected,
                $actual,
                sprintf('Expected response code %d, got %d.', $expected, $actual)
            );
        } catch (ExpectationFailedException $e) {
            $this->failMessageWithLastResponseBody(
                $e->getMessage()
            );
        }
    }

    /**
     * Fail with information containing message + last response body
     *
     * @param string $message
     */
    public function failMessageWithLastResponseBody(
        string $message
    ): void {
        self::fail(
            sprintf(
                '%s, last response body: %s',
                $message,
                $this->getReadableLastResponseBody()
            )
        );
    }

    /**
     * Validate a response code
     *
     * @param int $code
     *
     * @return void
     */
    protected function validateResponseCode(int $code): void
    {
        $this->assertGreaterThanOrEqual(
            100,
            $code,
            sprintf(
                'Response code must be between 100 and 599, got %d.',
                $code
            )
        );

        $this->assertLessThanOrEqual(
            599,
            $code,
            sprintf(
                'Response code must be between 100 and 599, got %d.',
                $code
            )
        );
    }

    /**
     * @BeforeScenario
     */
    public function setUpClient(): void
    {
        $this->client = static::createClient
        (
            [],
            [
                'HTTP_ACCEPT' => 'application/json',
            ]
        );
    }

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    /**
     * Assert that the response body contains all keys / values in the parameter
     *
     * @param PyStringNode $contains expected response body
     *
     * @return void
     * @throws \App\Tests\Behat\ArrayContainsComparator\Exception\AssertionFailedException
     *
     * @Then the response body contains JSON:
     */
    public function assertResponseBodyContainsJson(PyStringNode $contains): void
    {
        $this->requireResponse();

        // Decode the parameter to the step as an array and make sure it's valid JSON
        $contains = $this->jsonDecode((string)$contains);

        // Get the decoded response body and make sure it's decoded to an array
        $body = $this->getDecodedResponseBody();

        try {
            // Compare the arrays, on error this will throw an exception
            $this->assertTrue(
                $this->arrayContainsComparator->compare($contains, $body)
            );
        } catch (ArrayContainsComparatorException $e) {
            throw new AssertionFailedException(
                'Assertion that response body contains given JSON failed. Error message: '
                . $e->getMessage()
            );
        }
    }

    /**
     * Convert some variable to a JSON-array
     *
     * @param string $value The value to decode
     *
     * @return array
     * @throws InvalidArgumentException
     *
     */
    protected function jsonDecode(string $value): array
    {
        $decoded = json_decode(
            $value,
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException(
                'The supplied parameter is not a valid JSON object.'
            );
        }

        return $decoded;
    }

    /**
     * Print last response, useful to debugging
     *
     * @return void
     *
     * @Then print last response
     */
    public function printLastResponse(): void
    {
        self::fail(
            $this->getReadableLastResponseBody()
        );
    }

    /**
     * Get readable (as string) last response body
     *
     * @return string
     */
    public function getReadableLastResponseBody(): string
    {
        return json_encode(
            $this->getDecodedResponseBody(),
            JSON_THROW_ON_ERROR,
            512
        );
    }

    /**
     * Assert that the response body contains an array with a specific length
     *
     * @param int $length The length of the array
     *
     * @return void
     *
     * @Then the response body is a JSON array of length :length
     */
    public function assertResponseBodyJsonArrayLength($length): void
    {
        $this->requireResponse();
        $length = (int)$length;

        $this->assertCount(
            $length,
            $body = $this->getDecodedResponseBody(),
            sprintf(
                'Expected response body to be a JSON array with %d entr%s, got %d: "%s".',
                $length,
                $length === 1
                    ? 'y'
                    : 'ies',
                count($body),
                json_encode($body, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT, 512)
            )
        );
    }

    /**
     * Set the client instance
     *
     * @return self
     */
    public function setClient(ClientInterface $client) {
//        $this->client  = $client;
//
//        /** @var string|UriInterface */
//        $uri = $client->getConfig('base_uri');
//
//        $this->request = new Request('GET', $uri);
//
//        return $this;
    }

    /**
     * @inheritDoc
     */
    function setArrayContainsComparator(ArrayContainsComparator $comparator)
    {
        // TODO: Implement setArrayContainsComparator() method.
    }
}
