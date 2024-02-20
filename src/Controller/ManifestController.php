<?php

namespace App\Controller;

use App\Entity\IIIfManifest;
use App\Utils\Authenticator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ManifestController extends AbstractController
{
    /**
     * @var EntityManagerInterface
     */
    private $doctrine;

    public function __construct(EntityManagerInterface $doctrine) {
        $this->doctrine = $doctrine;
    }

    /**
     * @Route("/iiif/{iiifVersion}/{manifestId}/manifest.json", name="manifest", requirements={"iiifVersion"="2|3"})
     */
    public function manifestAction(Request $request, $iiifVersion, $manifestId = '')
    {
        // Make sure the service URL name ends with a trailing slash
        $baseUrl = rtrim($this->getParameter('service_url'), '/') . '/';

        if($request->getMethod() == 'HEAD') {
            $ids = $this->doctrine
                ->createQueryBuilder()
                ->select('m.id')
                ->from(IIIfManifest::class, 'm')
                ->where('m.manifestId = :id')
                ->setParameter('id', $baseUrl . $iiifVersion . '/' . $manifestId . '/manifest.json')
                ->getQuery()
                ->getResult();
            if(count($ids) > 0) {
                return new Response('', 200);
            } else {
                return new Response('', 404);
            }
        } else {
            $manifest = $this->doctrine->getRepository(IIIfManifest::class)->findOneBy(['manifestId' => $baseUrl . $iiifVersion . '/' . $manifestId . '/manifest.json']);
            if ($manifest === null) {
                //Check if we want to return the matching IIIF 3 manifest instead
                if($iiifVersion === '2') {
                    $manifest = $this->doctrine->getRepository(IIIfManifest::class)->findOneBy(['manifestId' => $baseUrl . '3/' . $manifestId . '/manifest.json']);
                    if($manifest === null) {
                        return new Response('Sorry, the requested document does not exist.', 404);
                    } else {
                        return new RedirectResponse($this->generateUrl('manifest', [ 'iiifVersion' => '3', 'manifestId' => $manifestId ]), 301);
                    }
                } else {
                    return new Response('Sorry, the requested document does not exist.', 404);
                }
            } else {
                $authenticated = true;
                $whitelist = $this->getParameter('authentication_whitelist');
                $whitelisted = false;
                if ($request->getClientIp() != null) {
                    if (in_array($request->getClientIp(), $whitelist)) {
                        $whitelisted = true;
                        $authenticated = true;
                    }
                }

                $data = json_decode($manifest->getData());
                if (!$whitelisted) {
                    if (property_exists($data, 'service')) {
                        if (property_exists($data->{'service'}, '@id')) {
                            if (strpos($data->{'service'}->{'@id'}, 'auth') > -1) {
                                $authenticated = false;
                            }
                        }
                    }
                }
                if (!$authenticated) {
                    // Authenticate the user through the AD FS with SAML
                    if (Authenticator::authenticate($this->getParameter('adfs_requirements'))) {
                        $authenticated = true;
                    }
                }
                if (!$authenticated) {
                    return new Response('Sorry, you are not allowed to access this document.');
                } else {
                    $headers = array(
                        'Content-Type' => 'application/json',
                        'Access-Control-Allow-Origin' => '*'
                    );
                    return new Response(json_encode($data, JSON_PRETTY_PRINT + JSON_UNESCAPED_SLASHES + JSON_UNESCAPED_UNICODE), 200, $headers);
                }
            }
        }
    }
}
