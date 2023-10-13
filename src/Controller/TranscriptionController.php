<?php

namespace App\Controller;

use App\Entity\Transcription;
use App\Utils\Authenticator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class TranscriptionController extends AbstractController
{
    /**
     * @Route("/iiif/{iiifVersion}/transcriptions/{manifestId}/{transcriptionId}.json", name="transcription", requirements={"iiifVersion"="2|3"})
     */
    public function transcriptionAction(Request $request, $iiifVersion, $manifestId = '', $transcriptionId = '')
    {
        // Make sure the service URL name ends with a trailing slash
        $baseUrl = rtrim($this->getParameter('service_url'), '/') . '/';

        $transcription = $this->get('doctrine')->getRepository(Transcription::class)->findOneBy(['transcriptionId' => $baseUrl . $iiifVersion . '/transcriptions/' . $manifestId . '/' . $transcriptionId . '.json']);
        if ($transcription === null) {
            //Check if we want to return the matching IIIF 3 transcription instead
            if($iiifVersion === '2') {
                $transcription = $this->get('doctrine')->getRepository(Transcription::class)->findOneBy(['manifestId' => $baseUrl . '3/transcriptions/' . $manifestId . '/' . $transcriptionId . '.json']);
                if($transcription === null) {
                    return new Response('Sorry, the requested document does not exist.', 404);
                } else {
                    return new RedirectResponse($this->generateUrl('transcription', [ 'iiifVersion' => '3', 'manifestId' => $manifestId, 'transcriptionId' => $transcriptionId ]), 301);
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

            $data = json_decode($transcription->getData());
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
