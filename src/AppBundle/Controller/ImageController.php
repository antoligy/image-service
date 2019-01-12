<?php

namespace AppBundle\Controller;

use AppBundle\Http\ImageRequest;
use Liip\ImagineBundle\Exception\Binary\Loader\NotLoadableException;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\{JsonResponse, Response};
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ImageController extends Controller
{
    /**
     * @Route("/v1/images/{uri}", name="images", requirements={"uri" = ".*"})
     */
    public function imageAction(Request $request, $uri = null)
    {
        if ($uri === null) {
            throw new HttpException(503, 'No image found!');
        }
        $logger = $this->get('logger');
        $logger->info('Received request for {$uri}.');

        $imageRequest = ImageRequest::fromRequest($request);
        $imageRequest->setUri($uri);

        $transformLoader = $this->get('responsive_images.transform_loader');
        $transforms = $transformLoader->transform($imageRequest);
        $filterManager = $this->get('liip_imagine.filter.manager');
        $dataManager = $this->get('liip_imagine.data.manager');

        if ($request->query->has('debug')) {
            return new JsonResponse($transforms);
        }

        try {
            $binary = $dataManager->find('responsive_image', $uri);
        } catch (NotLoadableException $e) {
            $message = 'Source image could not be found';
            $logger->error($message);
            throw new NotFoundHttpException($message, $e);
        } catch (Exception $e) {
            $logger->error($e->getMessage());
            throw new HttpException(503, $e->getMessage());
        }

        $binary = $filterManager->applyFilter($binary, 'responsive_image', []);

        $contentLength = mb_strlen($binary->getContent(), '8bit');
        if ($contentLength === false) {
            throw new HttpException(503, 'Error occurred whilst processing image.');
        }

        $response = new Response($binary->getContent());
        $response->headers->set('Content-Type', $binary->getMimeType());
        $response->headers->set('Content-Length', $contentLength);
        $response->setPublic();
        $response->setSharedMaxAge(3600 * 72);
        $response->setMaxAge(3600 * 72);
        $response->setVary('Accept-Encoding');

        return $response;
    }
}
