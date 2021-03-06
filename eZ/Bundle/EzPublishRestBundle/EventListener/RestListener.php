<?php
/**
 * File containing the RestValueResponseListener class.
 *
 * @copyright Copyright (C) 2012 eZ Systems AS. All rights reserved.
 * @license http://www.gnu.org/licenses/gpl-2.0.txt GNU General Public License v2
 * @version //autogentag//
 */
namespace eZ\Bundle\EzPublishRestBundle\EventListener;

use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\GetResponseForControllerResultEvent;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Form\Extension\Csrf\CsrfProvider\CsrfProviderInterface;
use eZ\Publish\Core\Base\Exceptions\UnauthorizedException;
use eZ\Bundle\EzPublishRestBundle\RestEvents;

/**
 * This class listens, as a service, for the kernel.view event, triggered when a controller method
 * didn't return a Response object.
 *
 * It converts the RestValue / Value Object to a Response using Visitors
 */
class RestListener implements EventSubscriberInterface
{
    /**
     * Name of the HTTP header containing CSRF token.
     */
    const CSRF_TOKEN_HEADER = "X-CSRF-Token";

    /**
     * @var \Symfony\Component\DependencyInjection\ContainerInterface
     */
    private $container;

    /**
     * @var \Symfony\Component\Form\Extension\Csrf\CsrfProvider\CsrfProviderInterface
     */
    private $csrfProvider;

    /**
     * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
     * @param \Symfony\Component\Form\Extension\Csrf\CsrfProvider\CsrfProviderInterface $csrfProvider
     */
    public function __construct( ContainerInterface $container, CsrfProviderInterface $csrfProvider = null )
    {
        $this->container = $container;
        $this->csrfProvider = $csrfProvider;
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return array(
            KernelEvents::VIEW => 'onKernelResultView',
            KernelEvents::EXCEPTION => 'onKernelExceptionView',
            KernelEvents::REQUEST => 'onKernelRequest'
        );
    }

    /**
     * @param \Symfony\Component\HttpKernel\Event\GetResponseForControllerResultEvent $event
     *
     * @throws \Exception
     */

    public function onKernelResultView( GetResponseForControllerResultEvent $event )
    {
        if ( $event->getRequestType() !== HttpKernelInterface::MASTER_REQUEST )
            return;

        if ( !$this->isRestRequest( $event->getRequest() ) )
            return;

        $result = $event->getControllerResult();

        $event->setResponse( $this->visitResult( $result ) );
        $event->stopPropagation();
    }

    /**
     * @param \Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent $event
     *
     * @throws \Exception
     *
     * @return void
     */
    public function onKernelExceptionView( GetResponseForExceptionEvent $event )
    {
        if ( $event->getRequestType() !== HttpKernelInterface::MASTER_REQUEST )
            return;

        if ( !$this->isRestRequest( $event->getRequest() ) )
            return;

        $result = $event->getException();

        $event->setResponse( $this->visitResult( $result ) );
        $event->stopPropagation();
    }

    /**
     * This method validates CSRF token if CSRF protection is enabled.
     *
     * @param \Symfony\Component\HttpKernel\Event\GetResponseEvent $event
     *
     * @throws \eZ\Publish\Core\Base\Exceptions\UnauthorizedException
     */
    public function onKernelRequest( GetResponseEvent $event )
    {
        if ( !$this->container->getParameter( 'form.type_extension.csrf.enabled' ) )
            return;

        // skip CSRF validation if no session is running
        if ( !$event->getRequest()->getSession()->isStarted() )
            return;

        if ( $event->getRequestType() !== HttpKernelInterface::MASTER_REQUEST )
            return;

        if ( !$this->isRestRequest( $event->getRequest() ) )
            return;

        if ( in_array( $event->getRequest()->getMethod(), array( 'GET', 'HEAD' ) ) )
            return;

        // TODO: add CSRF token to protect against force-login attacks
        if ( $event->getRequest()->get( "_route" ) == "ezpublish_rest_createSession" )
            return;

        if (
            !$event->getRequest()->headers->has( self::CSRF_TOKEN_HEADER )
            || !$this->csrfProvider->isCsrfTokenValid(
                $this->container->getParameter( 'ezpublish_rest.csrf_token_intention' ),
                $event->getRequest()->headers->get( self::CSRF_TOKEN_HEADER )
            )
        )
        {
            throw new UnauthorizedException(
                "Missing or invalid CSRF token",
                $event->getRequest()->getMethod() . " " . $event->getRequest()->getPathInfo()
            );
        }

        // Dispatching event so that CSRF token intention can be injected into Legacy Stack
        /** @var \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher */
        $eventDispatcher = $this->container->get( "event_dispatcher" );
        $eventDispatcher->dispatch( RestEvents::REST_CSRF_TOKEN_VALIDATED );
    }

    /**
     * @param \Symfony\Component\HttpFoundation\Request $request
     *
     * @return boolean
     */
    protected function isRestRequest( Request $request )
    {
        return (
            strpos(
                $request->getPathInfo(),
                $this->container->getParameter( 'ezpublish_rest.path_prefix' )
            ) === 0
        );
    }

    /**
     * @param mixed $result
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function visitResult( $result )
    {
        // visit response
        $viewDispatcher = $this->container->get( 'ezpublish_rest.output.visitor.dispatcher' );
        $message = $viewDispatcher->dispatch( $this->container->get( 'request' ), $result );

        // @todo It would be even better if visitors would return a Symfony message directly
        return new Response( $message->body, $message->statusCode, $message->headers );
    }
}
