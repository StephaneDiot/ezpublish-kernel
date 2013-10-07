<?php
/**
 * File containing the ImageStorage Converter class
 *
 * @copyright Copyright (C) 1999-2013 eZ Systems AS. All rights reserved.
 * @license http://www.gnu.org/licenses/gpl-2.0.txt GNU General Public License v2
 * @version //autogentag//
 */

namespace eZ\Publish\Core\FieldType\Image;

use eZ\Publish\Core\IO\Values\BinaryFileCreateStruct;
use eZ\Publish\SPI\Persistence\Content\VersionInfo;
use eZ\Publish\SPI\Persistence\Content\Field;
use eZ\Publish\Core\IO\IOService;
use eZ\Publish\Core\FieldType\GatewayBasedStorage;
use eZ\Publish\Core\IO\MetadataHandler;
use eZ\Publish\Core\Base\Exceptions\NotFoundException;
use eZ\Publish\SPI\FieldType\EventListener as FieldTypeEventListener;
use eZ\Publish\SPI\FieldType\FieldStorageEvents\PostPublishFieldStorageEvent;
use eZ\Publish\SPI\FieldType\FieldStorageEvent;

/**
 * External storage handler for images.
 *
 * IO handling:
 *
 * This handler uses two IOService instances, one for published images, the other one for drafts.
 * They can be identical, in which case both are stored using the same service, but if they're different,
 * images can be stored differently depending on their status.
 *
 * Path generation:
 *
 * Each storage engine provides its own path generation class, an instance of PathGenerator.
 * It offers the opportunity to generate different path based on the content's status, published or draft.
 *
 * Event handling:
 *
 * When a content with images is published, the storage handler offers the opportunity to move files from the
 * $draftIOService to the $publishedIOService, depending on data provided by $pathGenerator.
 */
class ImageStorage extends GatewayBasedStorage
{
    /**
     * Service used to manipulate images
     * @var IOService
     */
    protected $IOService;

    /**
     * Path generator
     * @var PathGenerator
     */
    protected $pathGenerator;

    /** @var MetadataHandler\ImageSize $imageSizeMetadataHandler */
    protected $imageSizeMetadataHandler;

    /**
     * Construct from gateways
     *
     * @param \eZ\Publish\Core\FieldType\StorageGateway[] $gateways
     * @param IOService $IOService
     * @param PathGenerator $imageSizeMetadataHandler
     * @param MetadataHandler $pathGenerator
     */
    public function __construct(
        array $gateways,
        IOService $IOService,
        PathGenerator $pathGenerator,
        MetadataHandler $imageSizeMetadataHandler
    )
    {
        parent::__construct( $gateways );
        $this->IOService = $IOService;
        $this->pathGenerator = $pathGenerator;
        $this->imageSizeMetadataHandler = $imageSizeMetadataHandler;
    }

    public function storeFieldData( VersionInfo $versionInfo, Field $field, array $context )
    {
        $contentMetaData = array(
            'fieldId' => $field->id,
            'versionNo' => $versionInfo->versionNo,
            'languageCode' => $field->languageCode,
        );

        // new image
        if ( isset( $field->value->externalData ) )
        {
            $targetPath = $this->getFieldPath(
                $versionInfo->status,
                $field->id,
                $versionInfo->versionNo,
                $field->languageCode
            ) . '/' . $field->value->externalData['fileName'];

            if ( !$this->IOService->loadBinaryFile( $targetPath ) )
            {
                $binaryFileCreateStruct = $this->IOService->newBinaryCreateStructFromLocalFile(
                    $field  ->value->externalData['id']
                );
                $binaryFileCreateStruct->id = $targetPath;
                $binaryFile = $this->IOService->createBinaryFile( $binaryFileCreateStruct );
            }
            else
            {
                $binaryFile = $this->IOService->loadBinaryFile( $targetPath );
            }
            $field->value->externalData['mimeType'] = $binaryFile->mimeType;
            $field->value->externalData['imageId'] = $versionInfo->contentInfo->id . '-' . $field->id;
            $field->value->externalData['uri'] = $binaryFile->uri;
            $field->value->externalData['id'] = ltrim( $binaryFile->uri, '/' );

            $field->value->data = array_merge(
                $field->value->externalData,
                $this->IOService->getMetadata( $this->imageSizeMetadataHandler, $binaryFile ),
                $contentMetaData
            );

            $field->value->externalData = null;
        }
        // existing image
        else
        {
            if ( $field->value->data === null )
            {
                // Store empty value only with content meta data
                $field->value->data = $contentMetaData;
                return false;
            }

            $binaryFile = $this->IOService->loadBinaryFile(
                $this->IOService->getExternalPath( $field->value->data['id'] )
            );
            $field->value->data = array_merge(
                $field->value->data,
                $this->IOService->getMetadata( $this->imageSizeMetadataHandler, $binaryFile ),
                $contentMetaData
            );
            $field->value->externalData = null;
        }

        $this->getGateway( $context )->storeImageReference( $field->value->data['uri'], $field->id );

        // Data has been updated and needs to be stored!
        return true;
    }

    /**
     * Returns the path where images for the defined $fieldId are stored
     *
     * @param mixed $fieldId
     * @param int $versionNo
     * @param string $languageCode
     * @param string $nodePathString
     *
     * @return string
     */
    protected function getFieldPath( $fieldId, $versionNo, $languageCode, $nodePathString )
    {
        return $this->pathGenerator->getStoragePathForField(
            $fieldId,
            $versionNo,
            $languageCode,
            $nodePathString
        );
    }

    public function getFieldData( VersionInfo $versionInfo, Field $field, array $context )
    {
        if ( $field->value->data !== null )
        {
            // @todo wrap this within a dedicated service that uses the handler + service under the hood
            // Required since images are stored with their full path, e.g. uri with a Legacy compatible IO handler
            $binaryFileId = $this->IOService->getExternalPath( $field->value->data['id'] );
            if ( ( $binaryFile = $this->IOService->loadBinaryFile( $binaryFileId ) ) === false )
            {
                throw new NotFoundException( '$field->value->data[id]', $field->value->data['id'] );
            }

            $field->value->data['fileSize'] = $binaryFile->size;
            $field->value->data['imageId'] = $versionInfo->contentInfo->id . '-' . $field->id;
            $field->value->data['uri'] = $binaryFile->uri;
        }
    }

    public function deleteFieldData( VersionInfo $versionInfo, array $fieldIds, array $context )
    {
        /** @var \eZ\Publish\Core\FieldType\Image\ImageStorage\Gateway $gateway */
        $gateway = $this->getGateway( $context );

        $fieldXmls = $gateway->getXmlForImages( $versionInfo->versionNo, $fieldIds );

        foreach ( $fieldXmls as $fieldId => $xml )
        {
            $storedFiles = $this->extractFiles( $xml );
            if ( $storedFiles === null )
            {
                continue;
            }

            foreach ( $storedFiles as $storedFilePath )
            {
                $gateway->removeImageReferences( $storedFilePath, $versionInfo->versionNo, $fieldId );
                if ( $gateway->countImageReferences( $storedFilePath ) === 0 )
                {
                    // @todo See todo above about full uri
                    $binaryFileId = $this->IOService->getExternalPath( $storedFilePath );
                    $binaryFile = $this->IOService->loadBinaryFile( $binaryFileId );
                    // If file can't be loaded it might be already deleted for some other language
                    if ( $binaryFile !== false )
                    {
                        $this->IOService->deleteBinaryFile( $binaryFile );
                    }
                }
            }
        }
    }

    /**
     * Extracts the field storage path from  the given $xml string
     *
     * @param string $xml
     *
     * @return string|null
     */
    protected function extractFiles( $xml )
    {
        if ( empty( $xml ) )
        {
            // Empty image value
            return null;
        }

        $files = array();

        $dom = new \DOMDocument();
        $dom->loadXml( $xml );
        if ( $dom->documentElement->hasAttribute( 'dirpath' ) )
        {
            $url = $dom->documentElement->getAttribute( 'url' );
            if ( empty( $url ) )
                return null;

            $files[] = $url;
            /** @var \DOMNode $childNode */
            foreach ( $dom->documentElement->childNodes as $childNode )
            {
                if ( $childNode->nodeName != 'alias' )
                    continue;

                $files[] = $childNode->getAttribute( 'url' );
            }
            return $files;
        }

        return null;
    }

    public function hasFieldData()
    {
        return true;
    }

    public function getIndexData( VersionInfo $versionInfo, Field $field, array $context )
    {
        // @todo: Correct?
        return null;
    }

    public function handleEvent( FieldStorageEvent $event, array $context )
    {
        if ( !$event instanceof PostPublishFieldStorageEvent )
            return;

        // If the path we currently have isn't the path to a draft, we have nothing to do
        if ( !$this->pathGenerator->isPathForDraft( $event->field->value->data['id'] ) )
            return false;

        // get new path from PathGenerator, and compare to currently stored path
        $publishedPath = $this->pathGenerator->getStoragePathForField(
            $event->versionInfo->status,
            $event->field->id,
            $event->versionInfo->versionNo,
            $event->field->languageCode,
            $this->getGateway( $context )->getNodePathString( $event->versionInfo, $event->field->id )
        ) . '/' . $event->field->value->data['fileName'];

        $binaryFileId = $this->IOService->getExternalPath( $event->field->value->data['id'] );
        $draftBinaryFile = $this->IOService->loadBinaryFile(
            $binaryFileId
        );

        $publishedFileCreateStruct = new BinaryFileCreateStruct(
            array(
                'id' => $publishedPath,
                'size' => $draftBinaryFile->size,
                'mimeType' => $draftBinaryFile->mimeType,
                'inputStream' => $this->IOService->getFileInputStream( $draftBinaryFile )
            )
        );

        $publishedBinaryFile = $this->IOService->createBinaryFile( $publishedFileCreateStruct );
        $this->IOService->deleteBinaryFile( $draftBinaryFile );

        $event->field->value->data['uri'] = $publishedBinaryFile->uri;
        $event->field->value->data['id'] = ltrim( $publishedBinaryFile->uri, '/' );
        $this->getGateway( $context )->storeImageReference(
            $event->field->value->data['uri'],
            $event->field->id
        );
        return true;
    }
}
