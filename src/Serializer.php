<?php

namespace As3\Modlr\Api\JsonApiOrg;

use As3\Modlr\Api\AdapterInterface;
use As3\Modlr\Api\SerializerException;
use As3\Modlr\Api\SerializerInterface;
use As3\Modlr\Metadata\AttributeMetadata;
use As3\Modlr\Metadata\EmbeddedPropMetadata;
use As3\Modlr\Metadata\EmbedMetadata;
use As3\Modlr\Metadata\RelationshipMetadata;
use As3\Modlr\Models\Collections\Collection;
use As3\Modlr\Models\Collections\EmbedCollection;
use As3\Modlr\Models\Embed;
use As3\Modlr\Models\Model;

/**
 * Serializes Models into the JSON API spec.
 * www.jsonapi.org
 *
 * @author Jacob Bare <jacob.bare@gmail.com>
 */
final class Serializer implements SerializerInterface
{
    /**
     * Denotes the current object depth of the serializer.
     *
     * @var int
     */
    private $depth = 0;

    /**
     * {@inheritDoc}
     */
    public function serialize(Model $model = null, AdapterInterface $adapter)
    {
        $serialized['data'] = null;
        if (null !== $model) {
            $serialized['data'] = $this->serializeModel($model, $adapter);
        }
        return (0 === $this->depth) ? $this->encode($serialized) : $serialized;
    }

    /**
     * {@inheritDoc}
     */
    public function serializeCollection(Collection $collection, AdapterInterface $adapter)
    {
        return $this->serializeArray($collection->allWithoutLoad(), $adapter);
    }

    /**
     * {@inheritDoc}
     */
    public function serializeArray(array $models, AdapterInterface $adapter)
    {
        $serialized['data'] = [];
        foreach ($models as $model) {
            $serialized['data'][] = $this->serializeModel($model, $adapter);
        }
        return (0 === $this->depth) ? $this->encode($serialized) : $serialized;
    }

    /**
     * {@inheritDoc}
     */
    public function serializeError($title, $message, $httpCode)
    {
        return $this->encode([
            'errors'    => [
                ['status' => (String) $httpCode, 'title' => $title, 'detail' => $message],
            ],
        ]);
    }

    /**
     * Serializes the "interior" of a model.
     * This is the serialization that takes place outside of a "data" container.
     * Can be used for root model and relationship model serialization.
     *
     * @param   Model               $model
     * @param   AdapterInterface    $adapter
     * @return  array
     */
    protected function serializeModel(Model $model, AdapterInterface $adapter)
    {
        $metadata = $model->getMetadata();
        $serialized = [
            'type'  => $model->getType(),
            'id'    => $model->getId(),
        ];
        if ($this->depth > 0) {
            // $this->includeResource($resource);
            return $serialized;
        }

        foreach ($metadata->getAttributes() as $key => $attrMeta) {
            $value = $model->get($key);
            $serialized['attributes'][$key] = $this->serializeAttribute($value, $attrMeta);
        }

        foreach ($metadata->getEmbeds() as $key => $embeddedPropMeta) {
            $value = $model->get($key);
            $serialized['attributes'][$key] = $this->serializeEmbed($value, $embeddedPropMeta);
        }

        $serialized['links'] = ['self' => $adapter->buildUrl($metadata, $model->getId())];

        $model->enableCollectionAutoInit(false);
        $this->increaseDepth();
        foreach ($metadata->getRelationships() as $key => $relMeta) {
            $relationship = $model->get($key);
            $serialized['relationships'][$key] = $this->serializeRelationship($model, $relationship, $relMeta, $adapter);
        }
        $this->decreaseDepth();
        $model->enableCollectionAutoInit(true);
        return $serialized;
    }

    /**
     * Serializes an attribute value.
     *
     * @param   mixed               $value
     * @param   AttributeMetadata   $attrMeta
     * @return  mixed
     */
    protected function serializeAttribute($value, AttributeMetadata $attrMeta)
    {
        if ('date' === $attrMeta->dataType && $value instanceof \DateTime) {
            $milliseconds = sprintf('%03d', round($value->format('u') / 1000, 0));
            return gmdate(sprintf('Y-m-d\TH:i:s.%s\Z', $milliseconds), $value->getTimestamp());
        }
        if ('array' === $attrMeta->dataType && empty($value)) {
            return [];
        }
        if ('object' === $attrMeta->dataType) {
            return (array) $value;
        }
        return $value;
    }

    /**
     * Serializes an embed value.
     *
     * @param   Embed|EmbedCollection|null  $value
     * @param   EmbeddedPropMetadata        $embeddedPropMeta
     * @return  array|null
     */
    protected function serializeEmbed($value, EmbeddedPropMetadata $embeddedPropMeta)
    {
        $embedMeta = $embeddedPropMeta->embedMeta;
        if ($embeddedPropMeta->isOne()) {
            return $this->serializeEmbedOne($embedMeta, $value);
        }
        return $this->serializeEmbedMany($embedMeta, $value);
    }

    /**
     * Serializes an embed one value.
     *
     * @param   EmbedMetadata   $embedMeta
     * @param   Embed|null      $embed
     * @return  array|null
     */
    protected function serializeEmbedOne(EmbedMetadata $embedMeta, Embed $embed = null)
    {
        if (null === $embed) {
            return;
        }
        $serialized = [];
        foreach ($embedMeta->getAttributes() as $key => $attrMeta) {
            $serialized[$key] = $this->serializeAttribute($embed->get($key), $attrMeta);
        }
        foreach ($embedMeta->getEmbeds() as $key => $embeddedPropMeta) {
            $serialized[$key] = $this->serializeEmbed($embed->get($key), $embeddedPropMeta);
        }

        return empty($serialized) ? null : $serialized;
    }

    /**
     * Serializes an embed many value.
     *
     * @param   EmbedMetadata   $embedMeta
     * @param   Embed|null      $embed
     * @return  array
     */
    protected function serializeEmbedMany(EmbedMetadata $embedMeta, EmbedCollection $collection)
    {
        $serialized = [];
        foreach ($collection as $embed) {
            $serialized[] = $this->serializeEmbedOne($embedMeta, $embed);
        }
        return $serialized;
    }

    /**
     * Serializes a relationship value
     *
     * @param   Model                       $owner
     * @param   Model|Model[]|null          $relationship
     * @param   RelationshipMetadata        $relMeta
     * @param   AdapterInterface            $adapter
     * @return  array
     */
    protected function serializeRelationship(Model $owner, $relationship = null, RelationshipMetadata $relMeta, AdapterInterface $adapter)
    {
        if ($relMeta->isOne()) {
            if (is_array($relationship)) {
                throw SerializerException::badRequest('Invalid relationship value.');
            }
            $serialized = $this->serializeHasOne($owner, $relationship, $adapter);
        } elseif (is_array($relationship) || null === $relationship) {
            $serialized = $this->serializeHasMany($owner, $relationship, $adapter);
        } else {
            throw SerializerException::badRequest('Invalid relationship value.');
        }

        $ownerMeta = $owner->getMetadata();
        $serialized['links'] = [
            'self'      => $adapter->buildUrl($ownerMeta, $owner->getId(), $relMeta->getKey()),
            'related'   => $adapter->buildUrl($ownerMeta, $owner->getId(), $relMeta->getKey(), true),
        ];
        return $serialized;
    }

    /**
     * Serializes a has-many relationship value
     *
     * @param   Model                   $owner
     * @param   Model[]|null            $models
     * @param   AdapterInterface        $adapter
     * @return  array
     */
    protected function serializeHasMany(Model $owner, array $models = null, AdapterInterface $adapter)
    {
        if (empty($models)) {
            return $this->serialize(null, $adapter);
        }
        return $this->serializeArray($models, $adapter);
    }

    /**
     * Serializes a has-one relationship value
     *
     * @param   Model                   $owner
     * @param   Model|null              $model
     * @param   AdapterInterface        $adapter
     * @return  array
     */
    protected function serializeHasOne(Model $owner, Model $model = null, AdapterInterface $adapter)
    {
        return $this->serialize($model, $adapter);
    }

    /**
     * Encodes the formatted payload array.
     *
     * @param   array   $payload
     * @return  string
     */
    private function encode(array $payload)
    {
        return json_encode($payload);
    }

    /**
     * Increases the serializer depth.
     *
     * @return  self
     */
    protected function increaseDepth()
    {
        $this->depth++;
        return $this;
    }

    /**
     * Decreases the serializer depth.
     *
     * @return  self
     */
    protected function decreaseDepth()
    {
        if ($this->depth > 0) {
            $this->depth--;
        }
        return $this;
    }
}
