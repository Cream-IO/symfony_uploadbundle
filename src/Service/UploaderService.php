<?php

namespace CreamIO\UploadBundle\Service;

use CreamIO\BaseBundle\Exceptions\APIError;
use CreamIO\BaseBundle\Exceptions\APIException;
use CreamIO\BaseBundle\Service\APIService;
use CreamIO\UploadBundle\Model\UserStoredFile;
use GBProd\UuidNormalizer\UuidNormalizer;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * Class UploaderService.
 */
class UploaderService
{
    private const BAD_CLASSNAME_ERROR = 'The classname provided to generate uploaded file does not extends UserStoredFile.';
    private const NOT_EXISTING_CLASS_PROPERTY_ERROR = 'The property provided for file name store does not exist.';
    private const NO_FILE_PROVIDED_ERROR = 'No file provided.';

    /**
     * @var APIService Injected API service
     */
    private $apiService;

    /**
     * @var string Injected upload directory from config
     */
    private $targetDirectory;

    /**
     * @var string Injected default file upload entity from config
     */
    private $defaultClassToGenerate;

    /**
     * @var string Injected default file property for default file upload entity
     */
    private $defaultClassFileProperty;

    /**
     * UploaderService constructor.
     *
     * @param string             $targetDirectory          Target upload directory, injected from config file
     * @param string             $defaultClassToGenerate   Classname to generate by default if not provided in handleUpload method. Example : "App\Entity\GalleryImage"
     * @param string             $defaultClassFileProperty Property in the file upload entity that contain the file name by default if not provided in handleUpload
     * @param APIService         $apiService               Injected API service from base bundle
     *
     * @throws APIException If class check fails
     */
    public function __construct(string $targetDirectory, string $defaultClassToGenerate, string $defaultClassFileProperty, APIService $apiService)
    {
        $this->checkClass($defaultClassToGenerate, $defaultClassFileProperty);
        $this->apiService = $apiService;
        $this->targetDirectory = $targetDirectory;
        $this->defaultClassToGenerate = $defaultClassToGenerate;
        $this->defaultClassFileProperty = $defaultClassFileProperty;
    }

    /**
     * Check if class name provided implements UserStoredFile::class and provided file property exists.
     *
     * @param string $classToGenerate Classname to generate
     * @param string $fileProperty    Property in the file upload entity that contain the file name
     *
     * @throws APIException If validation failed, with message
     */
    public function checkClass(string $classToGenerate, string $fileProperty): void
    {
        if (false === \is_subclass_of($classToGenerate, UserStoredFile::class)) {
            $APIError = new APIError(Response::HTTP_INTERNAL_SERVER_ERROR, self::BAD_CLASSNAME_ERROR);

            throw new APIException($APIError);
        }
        if (false === \property_exists($classToGenerate, $fileProperty)) {
            $APIError = new APIError(Response::HTTP_INTERNAL_SERVER_ERROR, self::NOT_EXISTING_CLASS_PROPERTY_ERROR);

            throw new APIException($APIError);
        }
    }

    /**
     * Generates a serializer to denormalize file upload entities.
     *
     * @return Serializer
     */
    private function generateSerializer(): Serializer
    {
        $encoders = [new JsonEncoder()];
        $normalizers = [new DateTimeNormalizer('d-m-Y H:i:s'), new ObjectNormalizer()];

        return new Serializer($normalizers, $encoders);
    }

    /**
     * Get file from the request and check it exists
     *
     * @param Request $request Handled HTTP request
     *
     * @throws APIException If no file was provided
     *
     * @return File
     */
    private function getFileFromRequest(Request $request): File
    {
        $tempFile = $request->files->get('uploaded-file');
        if (null === $tempFile) {
            throw $this->apiService->error(Response::HTTP_BAD_REQUEST, self::NO_FILE_PROVIDED_ERROR);
        }

        return $tempFile;
    }

    /**
     * Main upload handling method, moves the file and generate the file upload entity.
     *
     * @param Request     $request         Handled HTTP request
     * @param bool        $validate        Validate or not the entity during upload processing ? Useful when you need to add some parameters to the entity before validation
     * @param null|string $classToGenerate Classname to generate. Example : "App\Entity\GalleryImage" or GalleryImage::class
     * @param null|string $fileProperty    Property in the file upload entity that contain the file name
     *
     * @throws APIException If class check fails or no file provided
     *
     * @return UserStoredFile File upload entity
     */
    public function handleUpload(Request $request, bool $validate = true, ?string $classToGenerate = null, ?string $fileProperty = null): UserStoredFile
    {
        $fileProperty = $fileProperty ?? $this->defaultClassFileProperty;
        $classToGenerate = $classToGenerate ?? $this->defaultClassToGenerate;
        $this->checkClass($classToGenerate, $fileProperty);
        $tmpFile = $this->getFileFromRequest($request);
        $file = $this->move($tmpFile);
        $uploadedFile = $this->denormalizeEntity($request, $classToGenerate, $fileProperty, $file);
        if ($validate) {
            $this->apiService->validateEntity($uploadedFile);
        }

        return $uploadedFile;
    }

    /**
     * Generate a file upload entity.
     *
     * @param Request $request         Handled HTTP request
     * @param string  $classToGenerate Classname to generate. Example : "App\Entity\GalleryImage"
     * @param string  $fileProperty    Property in your entity that contain the file name
     * @param File  $filename        File to store in file upload entity
     *
     * @return UserStoredFile File upload entity
     */
    private function denormalizeEntity(Request $request, string $classToGenerate, string $fileProperty, File $filename): UserStoredFile
    {
        $postDatas = $request->request->all();
        $postDatas[$fileProperty] = $filename;

        return $this->generateSerializer()->denormalize($postDatas, $classToGenerate);
    }

    /**
     * Move the uploaded file to the upload directory.
     *
     * @param UploadedFile $file
     *
     * @return File Filename
     */
    private function move(File $file): File
    {
        $fileName = $this->generateUniqueFilename($file->guessExtension());

        return $file->move($this->getTargetDirectory(), $fileName);
    }

    /**
     * Generated a md5 filename based on uniqid.
     *
     * @param null|string $fileExtension
     *
     * @return string
     */
    private function generateUniqueFilename(?string $fileExtension): string
    {
        $uniqueName = \md5(\uniqid('creamio_upload_', true));
        if (null !== $fileExtension) {
            $uniqueName = \sprintf('%s.%s', $uniqueName, $fileExtension);
        }

        return $uniqueName;
    }

    /**
     * Returns the upload directory.
     *
     * @return string
     */
    public function getTargetDirectory(): string
    {
        return $this->targetDirectory;
    }
}
