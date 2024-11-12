<?php
/**
 * Created by PhpStorm.
 * User: mike
 * Date: 10/31/19
 * Time: 1:22 PM
 */

namespace App\Controller;


use App\Utils\Authenticator;
use SimpleSAML_Auth_Simple;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class AuthCheckController extends AbstractController
{
    private $parameterBag;

    public function __construct(ParameterBagInterface $parameterBag) {
        $this->parameterBag = $parameterBag;
    }

    #[Route("/authcheck", name:"authcheck")]
    public function authenticateAction(Request $request)
    {
        $adfsRequirement = $this->parameterBag->get('adfs_requirements');

        // Forbidden by default
        $returnCode = 403;

        if($adfsRequirement['public']) {
            $returnCode = 200;
        } else {
            $auth = new SimpleSAML_Auth_Simple('default-sp');
            if ($auth->isAuthenticated()) {
                if(Authenticator::isAllowed($auth->getAttributes(), $adfsRequirement)) {
                    // The user is already authenticated, everything is in order
                    $returnCode = 200;
                }
            } else {
                // The user is not yet authenticated, send them to the login page
                $returnCode = 302;
            }
        }

        if($returnCode == 200) {
            return new Response('', $returnCode);
        } else if($returnCode == 302) {
            $url = $this->generateUrl('authenticate', array(), UrlGeneratorInterface::ABSOLUTE_URL);
            return new Response('You are being redirected to ' . $url, $returnCode, array('Location' => $url));
        } else {
            return new Response('Sorry, you are not allowed to access this document.', 403);
        }
    }
}
