<?php

namespace Encore\Admin\Middleware;

use Closure;
use Encore\Admin\Facades\Admin;
use Illuminate\Http\Request;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;

class Pjax
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     *
     * @return Response
     */
    public function handle($request, Closure $next)
    {
        $response = $next($request);

        if (!$request->pjax() || $response->isRedirection() || Admin::guard()->guest()) {
            return $response;
        }

        // Downloads, streams and JSON retain their original transport and status.
        if ($response instanceof \Symfony\Component\HttpFoundation\StreamedResponse
            || $response instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse
            || !str_contains(strtolower($response->headers->get('Content-Type', '')), 'text/html')) {
            return $response;
        }

        // Failed responses belong to the host exception handler. In particular they
        // need not carry an attached exception, and must not become successful redirects.
        if (!$response->isSuccessful()) {
            return $this->handleErrorResponse($response);
        }

        try {
            $this->filterResponse($response, $request->header('X-PJAX-CONTAINER'))
                ->setUriHeader($response, $request);
        } catch (\InvalidArgumentException|\Symfony\Component\CssSelector\Exception\ExceptionInterface $exception) {
            // Invalid/missing selectors retain the complete HTML for PJAX fallback.
        }

        return $response;
    }

    /**
     * Send a response through this middleware.
     *
     * @param Response $response
     */
    public static function respond(Response $response)
    {
        $next = function () use ($response) {
            return $response;
        };

        (new static())->handle(Request::capture(), $next)->send();

        exit;
    }

    /**
     * Handle Response with exceptions.
     *
     * @param Response $response
     *
     * @return Response
     */
    protected function handleErrorResponse(Response $response)
    {
        return $response;
    }

    /**
     * Prepare the PJAX-specific response content.
     *
     * @param Response $response
     * @param string   $container
     *
     * @return $this
     */
    protected function filterResponse(Response $response, $container)
    {
        $crawler = new Crawler($response->getContent());

        $response->setContent(
            $this->makeTitle($crawler).
            $this->fetchContents($crawler, $container)
        );

        return $this;
    }

    /**
     * Prepare an HTML title tag.
     *
     * @param Crawler $crawler
     *
     * @return string
     */
    protected function makeTitle($crawler)
    {
        $titles = $crawler->filter('head > title');
        $pageTitle = $titles->count() ? $titles->html() : '';

        return "<title>{$pageTitle}</title>";
    }

    /**
     * Fetch the PJAX-specific HTML from the response.
     *
     * @param Crawler $crawler
     * @param string  $container
     *
     * @return string
     */
    protected function fetchContents($crawler, $container)
    {
        if (!is_string($container) || trim($container) === '') {
            throw new \InvalidArgumentException('Missing PJAX container.');
        }

        $content = $crawler->filter($container);

        if (!$content->count()) {
            throw new \InvalidArgumentException('Missing PJAX container.');
        }

        return $this->decodeUtf8HtmlEntities($content->html());
    }

    /**
     * Decode utf-8 characters to html entities.
     *
     * @param string $html
     *
     * @return string
     */
    protected function decodeUtf8HtmlEntities($html)
    {
        return preg_replace_callback('/(&#[0-9]+;)/', function ($html) {
            return mb_convert_encoding($html[1], 'UTF-8', 'HTML-ENTITIES');
        }, $html);
    }

    /**
     * Set the PJAX-URL header to the current uri.
     *
     * @param Response $response
     * @param Request  $request
     */
    protected function setUriHeader(Response $response, Request $request)
    {
        $response->headers->set(
            'X-PJAX-URL',
            $request->getRequestUri()
        );
    }
}
