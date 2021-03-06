<?php

/**
 * (c) Fabryka Stron Internetowych sp. z o.o <info@fsi.pl>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FSi\DoctrineExtensions\Versionable;

use Doctrine\Common\EventArgs;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Persistence\Mapping\ClassMetadata;
use Doctrine\Common\Persistence\ObjectManager;
use Doctrine\Common\Annotations\AnnotationException;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\UnitOfWork;
use Doctrine\ORM\Query;
use FSi\Component\Reflection\ReflectionProperty;
use FSi\Component\Metadata\ClassMetadataInterface;
use FSi\Component\PropertyObserver\PropertyObserver;
use FSi\DoctrineExtensions\Mapping\MappedEventSubscriber;
use FSi\DoctrineExtensions\Versionable\Exception;
use FSi\DoctrineExtensions\Versionable\Strategy\StrategyInterface;
use FSi\DoctrineExtensions\Versionable\Mapping\ClassMetadata as VersionableClassMetadata;

/**
 * @author Lukasz Cybula <lukasz.cybula@fsi.pl>
 */
class VersionableListener extends MappedEventSubscriber
{
    /**
     * Cached references to versioning strategies instances for specific classes
     *
     * @var array
     */
    private $strategies = array();

    /**
     * Array of version numbers that override current version numbers for specific objects
     *
     * @var array
     */
    private $versions = array();

    /**
     * Array of property observers for each object manager
     *
     * @var array
     */
    private $_propertyObservers = array();

    /**
     * Specifies the list of events to listen
     *
     * @return array
     */
    public function getSubscribedEvents()
    {
        return array(
            'postLoad',
            'postHydrate',
            'preFlush'
        );
    }

    /**
     * @param ObjectManager $objectManager
     * @param string $class
     * @return VersionableClassMetadata
     */
    public function getExtendedMetadata(ObjectManager $objectManager, $class)
    {
        return parent::getExtendedMetadata($objectManager, $class);
    }

    /**
     * {@inheritDoc}
     */
    public function validateExtendedMetadata(ClassMetadata $baseClassMetadata, ClassMetadataInterface $extendedClassMetadata)
    {
        if (!($extendedClassMetadata instanceof VersionableClassMetadata)) {
            throw new Exception\MappingException('Metadata passed to LoStorage listener is not an object of class FSi\DoctrineExtensions\LoStorage\Mapping\ClassMetadata');
        }
        if (isset($extendedClassMetadata->versionAssociation)) {
            // This class is versionable
            if (!$baseClassMetadata->isCollectionValuedAssociation($extendedClassMetadata->versionAssociation)) {
                throw new Exception\MappingException('Entity \'' . $baseClassMetadata->getName() . '\' is versionable it has no apriopriate versions\' collection named \'' . $extendedClassMetadata->versionAssociation . '\'');
            }
            if (!$extendedClassMetadata->hasVersionableProperties()) {
                throw new Exception\MappingException('Entity \'' . $baseClassMetadata->getName() . '\' is versionable but has no versionable properties');
            } else {
                foreach ($extendedClassMetadata->getVersionableProperties() as $property => $field) {
                    if ($baseClassMetadata->hasField($property))
                        throw new Exception\MappingException('Property \''.$property.'\' of class \''.$baseClassMetadata->getName().'\' is versionable so it cannot be persistent');
                }
            }
            if (!isset($extendedClassMetadata->versionProperty)) {
                throw new Exception\MappingException('Entity \'' . $baseClassMetadata->getName() . '\' is versionable but has no field marked with @Versionable\Version annotation');
            } else if (!$baseClassMetadata->hasField($extendedClassMetadata->versionProperty) ||
                $baseClassMetadata->hasAssociation($extendedClassMetadata->versionProperty)) {
                throw new Exception\MappingException('Property \''.$extendedClassMetadata->versionProperty.'\' of class \''.$baseClassMetadata->getName().'\' holds current version number so it has to be a persistent field and not an association');
            }
            if (isset($extendedClassMetadata->statusProperty)) {
                throw new Exception\MappingException('Entity \'' . $baseClassMetadata->getName() . '\' is versionable so it cannot contain property marked with @Versionable\Status annotation');
            }
        } else if (isset($extendedClassMetadata->versionProperty) || isset($extendedClassMetadata->statusProperty)) {
            // This class represents version entity
            if (!isset($extendedClassMetadata->versionProperty))
                throw new Exception\MappingException('Entity \'' . $baseClassMetadata->getName() . '\' is a version entity but has no field marked with @Versionable\Version annotation');
            else if (!$baseClassMetadata->hasField($extendedClassMetadata->versionProperty) ||
                $baseClassMetadata->hasAssociation($extendedClassMetadata->versionProperty)) {
                throw new Exception\MappingException('Property \''.$extendedClassMetadata->versionProperty.'\' of class \''.$baseClassMetadata->getName().'\' holds version number so it has to be a persistent field and not an association');
            }
            if (isset($extendedClassMetadata->statusProperty) && !$baseClassMetadata->hasField($extendedClassMetadata->statusProperty))
                throw new Exception\MappingException('Property \''.$extendedClassMetadata->statusProperty.'\' of class \''.$baseClassMetadata->getName().'\' holds version status so it has to be a persistent field and not an association');
        }
    }

    /**
     * Get the namespace of extension event subscriber
     * used for cache id of extensions also to know where
     * to find Mapping drivers and event adapters
     *
     * @return string
     */
    public function getNamespace()
    {
        return __NAMESPACE__;
    }

    /**
     * Returns PropertyObserver for specified ObjectManager
     *
     * @param \Doctrine\Common\Persistence\ObjectManager $objectManager
     * @return \FSi\Component\PropertyObserver\PropertyObserver
     */
    protected function getPropertyObserver(ObjectManager $objectManager)
    {
        $oid = spl_object_hash($objectManager);
        if (!isset($this->_propertyObservers[$oid])) {
            $this->_propertyObservers[$oid] = new PropertyObserver();
        }
        return $this->_propertyObservers[$oid];
    }

    /**
     * Returns versioning strategy instance for specified class
     *
     * @param \Doctrine\Common\Persistence\ObjectManager $objectManager
     * @param string $class
     * @return \FSi\DoctrineExtensions\Versionable\Strategy\StrategyInterface
     * @throws \FSi\DoctrineExtensions\Versionable\Exception\RuntimeException
     */
    protected function getStrategy(ObjectManager $objectManager, $class)
    {
        $omHash = spl_object_hash($objectManager);
        if (!isset($this->strategies[$omHash][$class])) {
            $extendedMeta = $this->getExtendedMetadata($objectManager, $class);
            $meta = $objectManager->getClassMetadata($class);
            $versionClass = $meta->getAssociationTargetClass($extendedMeta->versionAssociation);
            $versionExtendedMeta = $this->getExtendedMetadata($objectManager, $versionClass);
            $strategyClass = $extendedMeta->strategy;
            $strategy = new $strategyClass($objectManager, $extendedMeta, $versionExtendedMeta);
            if (!($strategy instanceof StrategyInterface))
                throw new Exception\RuntimeException('Strategy object of class "'.$class.'" does not implement FSi\DoctrineExtensions\Versionable\Strategy\StrategyInterface');
            $this->strategies[$omHash][$class] = $strategy;
        }
        return $this->strategies[$omHash][$class];
    }

    /**
     * Load specific version into specified object
     *
     * @param \Doctrine\Common\Persistence\ObjectManager $objectManager
     * @param object $object
     * @param integer $version
     */
    public function loadVersion(ObjectManager $objectManager, $object, $version = null)
    {
        $meta            = $objectManager->getClassMetadata(get_class($object));
        $extendedMeta    = $this->getExtendedMetadata($objectManager, $meta->getName());
        if ($extendedMeta->hasVersionableProperties())
            $this->loadVersionProperties($meta, $extendedMeta, $objectManager, $object, $version);
    }

    /**
     * Load specific version into specified object
     *
     * @param \Doctrine\Common\EventArgs $eventArgs
     */
    public function postHydrate(EventArgs $eventArgs)
    {
        $this->postLoad($eventArgs);
    }

    /**
     * Override published version number for specific entity. Pass $version = null to disable overriding.
     *
     * @param \Doctrine\Common\Persistence\ObjectManager $objectManager
     * @param object $object
     * @param int $version
     * @return \FSi\DoctrineExtensions\Versionable\VersionableListener
     */
    public function setVersionForEntity(ObjectManager $objectManager, $object, $version = null)
    {
        $meta = $objectManager->getClassMetadata(get_class($object));
        $id = $meta->getIdentifierValues($object);
        $this->setVersionForId($objectManager, $meta->getName(), $id, $version);
        return $this;
    }

    /**
     * Get overrided version number for specific entity. Returns null if it has not overrided version number.
     *
     * @param \Doctrine\Common\Persistence\ObjectManager $objectManager
     * @param object $object
     * @return int|null
     */
    public function getVersionForEntity(ObjectManager $objectManager, $object)
    {
        $meta = $objectManager->getClassMetadata(get_class($object));
        $id = $meta->getIdentifierValues($object);
        return $this->getVersionForId($objectManager, $meta->getName(), $id);
    }

    /**
     * Override published version number for entity with specific $id. Pass $version = null to disable overriding.
     *
     * @param \Doctrine\Common\Persistence\ObjectManager $objectManager
     * @param string $class
     * @param int|array $id
     * @param int|null $version
     * @return \FSi\DoctrineExtensions\Versionable\VersionableListener
     */
    public function setVersionForId(ObjectManager $objectManager, $class, $id, $version = null)
    {
        $omHash = spl_object_hash($objectManager);
        $class = (string)$class;
        $id = $this->getKeyFromId($id);
        if (!isset($this->versions[$omHash]))
            $this->versions[$omHash] = array();
        if (!isset($this->versions[$omHash][$class]))
            $this->versions[$omHash][$class] = array();
        if (isset($version))
            $this->versions[$omHash][$class][$id] = (int)$version;
        else
            unset($this->versions[$omHash][$class][$id]);
        return $this;
    }

    /**
     * Get overrided version number for entity with specific $id. Returns null if it has not overrided version number.
     *
     * @param \Doctrine\Common\Persistence\ObjectManager $objectManager
     * @param string $class
     * @param int|array $id
     * @return int|null
     */
    public function getVersionForId(ObjectManager $objectManager, $class, $id)
    {
        $omHash = spl_object_hash($objectManager);
        $class = (string)$class;
        $id = $this->getKeyFromId($id);
        if (isset($this->versions[$omHash][$class][$id]))
            return $this->versions[$omHash][$class][$id];
        else
            return null;
    }

    /**
     * Return an array of overrided version numbers for entities of specified class
     *
     * @param \Doctrine\Common\Persistence\ObjectManager $objectManager
     * @param string $class
     * @return array
     */
    public function getVersionsForClass(ObjectManager $objectManager, $class)
    {
        $omHash = spl_object_hash($objectManager);
        if (isset($this->versions[$omHash][$class]))
            return $this->versions[$omHash][$class];
        else
            return array();
    }

    /**
     * Return string key from specified object identifier which can be an array
     *
     * @param mixed $id
     * @return string
     */
    protected function getKeyFromId($id)
    {
        if (!is_array($id))
            $id = array($id);
        return implode('|', $id);
    }

    /**
     * Load version fields into object properties
     *
     * @param \Doctrine\Common\Persistence\Mapping\ClassMetadata $meta
     * @param \FSi\DoctrineExtensions\Versionable\Mapping\ClassMetadata $versionableMeta
     * @param \Doctrine\Common\Persistence\ObjectManager $objectManager
     * @param object $object
     * @param integer $currentVersionNumber
     * @throws \FSi\DoctrineExtensions\Versionable\Exception\RuntimeException
     */
    protected function loadVersionProperties(ClassMetadata $meta, VersionableClassMetadata $versionableMeta, ObjectManager $objectManager, $object,
        $currentVersionNumber = null)
    {
        $versions = $meta->getFieldValue($object, $versionableMeta->versionAssociation);
        // Do not try to find version if versions association is not yet initialized i.e. during postLoad
        if (!isset($versions))
            return;
        if (!isset($currentVersionNumber))
            $currentVersionNumber = $this->getVersionForEntity($objectManager, $object);
        if (!isset($currentVersionNumber))
            $currentVersionNumber = $meta->getFieldValue($object, $versionableMeta->versionProperty);
        if (!isset($currentVersionNumber))
            return;

        $versionEntity = $meta->getAssociationTargetClass($versionableMeta->versionAssociation);
        $versionMeta = $objectManager->getClassMetadata($versionEntity);
        $versionNumberField = $this->getVersionNumberField($objectManager, $versionMeta->name);

        $currentVersion = null;
        if (isset($versions))
            $currentVersion = $this->findVersion($versions, $versionMeta, $versionNumberField, $currentVersionNumber);

        if (!isset($currentVersion))
            throw new Exception\RuntimeException('Versionable object of class "'.$meta->getName().'" cannot load current version with number '.$currentVersionNumber);

        $propertyObserver = $this->getPropertyObserver($objectManager);
        foreach ($versionableMeta->getVersionableProperties() as $property => $versionField) {
            $propertyObserver->setValue($object, $property, $versionMeta->getFieldValue($currentVersion, $versionField));
        }
    }

    /**
     * After loading the entity copy the current version fields into non-persistent versionable properties
     *
     * @param \Doctrine\Common\EventArgs $eventArgs
     * @return void
     */
    public function postLoad(EventArgs $eventArgs)
    {
        $eventAdapter    = $this->getEventAdapter($eventArgs);
        $objectManager   = $eventAdapter->getObjectManager();
        $object          = $eventAdapter->getObject();
        $meta            = $objectManager->getClassMetadata(get_class($object));
        $versionableMeta = $this->getExtendedMetadata($objectManager, $meta->name);

        if ($versionableMeta->hasVersionableProperties())
            $this->loadVersionProperties($meta, $versionableMeta, $objectManager, $object);
    }

    /**
     * Helper method to insert, remove or update version entities associated with specified object
     *
     * @param \Doctrine\Common\Persistence\ObjectManager $objectManager
     * @param \Doctrine\Common\Persistence\Mapping\ClassMetadata $meta
     * @param \FSi\DoctrineExtensions\Versionable\Mapping\ClassMetadata $versionableMeta
     * @param $object
     * @throws Exception\MappingException
     * @throws Exception\RuntimeException
     * @param object $object
     */
    protected function updateVersions(ObjectManager $objectManager, ClassMetadata $meta, VersionableClassMetadata $versionableMeta, $object)
    {
        $unitOfWork = $objectManager->getUnitOfWork();
        $propertyObserver = $this->getPropertyObserver($objectManager);
        $strategy = $this->getStrategy($objectManager, $meta->getName());

        $versionEntity = $meta->getAssociationTargetClass($versionableMeta->versionAssociation);
        $objectVersionProperty = $this->getVersionProperty($objectManager, $meta->getName(), $versionableMeta, $versionEntity);
        if (!isset($objectVersionProperty))
            throw new Exception\RuntimeException("Version's number field must be marked as versionable in class '".$meta->name."'");
        $versionToUpdateNumber = ReflectionProperty::factory($meta->name, $objectVersionProperty)->getValue($object);
        $currentVersionNumber = $meta->getFieldValue($object, $versionableMeta->versionProperty);

        $versionMeta = $objectManager->getClassMetadata($versionEntity);
        $versionNumberField = $this->getVersionNumberField($objectManager, $versionEntity);
        $versionAssociation = $meta->getAssociationMapping($versionableMeta->versionAssociation);

        $versions = $meta->getFieldValue($object, $versionableMeta->versionAssociation);

        $versionToUpdate = null;
        if (isset($versions) && isset($versionToUpdateNumber))
            $versionToUpdate = $this->findVersion($versions, $versionMeta, $versionNumberField, $versionToUpdateNumber);

        $versionToUpdate = $strategy->getVersionToUpdate($object, $versionToUpdate);
        if (!isset($versionToUpdate))
            $createNewVersion = true;

        foreach ($versionableMeta->getVersionableProperties() as $property => $versionField) {
            if (!$propertyObserver->hasSavedValue($object, $property) || $propertyObserver->hasValueChanged($object, $property)) {
                $propertyValue = ReflectionProperty::factory($meta->getName(), $property)->getValue($object);
                if (isset($propertyValue)) {
                    if (!isset($versionToUpdate)) {
                        $versionToUpdate = new $versionEntity();
                        if ($meta->getIdentifierValues($object))
                            $newVersionNumber = $this->getNextVersionNumber($objectManager, $object, $versionMeta,
                                $versionAssociation, $versionNumberField);
                        else
                            $newVersionNumber = 1;
                        $versionMeta->setFieldValue($versionToUpdate, $versionNumberField, $newVersionNumber);
                        $versionMeta->setFieldValue($versionToUpdate, $versionAssociation['mappedBy'], $object);

                        if (isset($versionAssociation['indexBy'])) {
                            $index = $versionMeta->getFieldValue($versionToUpdate, $versionAssociation['indexBy']);
                            $versions[$index] = $versionToUpdate;
                        } else
                            $versions[] = $versionToUpdate;
                        $strategy->prepareNewVersion($object, $versionToUpdate);
                        $objectManager->persist($versionToUpdate);
                    }
                    if (!$versionMeta->hasField($versionField)) {
                        throw new Exception\MappingException('Version entity "'.$versionEntity.'" has no field named "'.$versionField.'" which is mapped as @Versionable in entity "'.$meta->name.'"');
                    }
                    $versionMeta->setFieldValue($versionToUpdate, $versionField, $propertyValue);
                } else if ($versionToUpdate) {
                    $versionMeta->setFieldValue($versionToUpdate, $versionField, null);
                }
                $propertyObserver->saveValue($object, $property);
            }
        }
        if (!isset($versionToUpdateNumber) && isset($versionToUpdate)) {
            $versionToUpdateNumber = $versionMeta->getFieldValue($versionToUpdate, $versionNumberField);
            $propertyObserver->setValue($object, $objectVersionProperty, $versionToUpdateNumber);
        }
        if (!isset($currentVersionNumber) && isset($versionToUpdate)) {
            $currentVersionNumber = $versionMeta->getFieldValue($versionToUpdate, $versionNumberField);
            $meta->setFieldValue($object, $versionableMeta->versionProperty, $currentVersionNumber);
        }
        $originalObject = $unitOfWork->getOriginalEntityData($object);
        if (isset($originalObject[$versionableMeta->versionProperty]) && ($currentVersionNumber !== $originalObject[$versionableMeta->versionProperty]) ||
            !isset($originalObject[$versionableMeta->versionProperty]) && isset($currentVersionNumber)) {
            if (!$this->findVersion($versions, $versionMeta, $versionNumberField, $currentVersionNumber))
                throw new Exception\RuntimeException('Version number \''.$currentVersionNumber.'\' does not exists');
            $strategy->currentVersionChanged($object, isset($originalObject[$versionableMeta->versionProperty])?$originalObject[$versionableMeta->versionProperty]:null);
        }
    }

    /**
     * Get next version number for newly created version entity
     *
     * @param \Doctrine\Common\Persistence\ObjectManager $objectManager
     * @param object $object
     * @param \Doctrine\Common\Persistence\Mapping\ClassMetadata $versionMeta
     * @param array $versionAssociation
     * @param string $versionNumberField
     * @return int
     */
    protected function getNextVersionNumber(ObjectManager $objectManager, $object, ClassMetadata $versionMeta,
        array $versionAssociation, $versionNumberField)
    {
        /* @var $query Query */
        $query = $objectManager->createQuery("
            SELECT MAX(v.".$versionNumberField.") + 1
            FROM ".$versionMeta->getName()." v
            WHERE v.".$versionAssociation['mappedBy']." = ?1");
        $query->setParameter(1, $object);
        return (int)$query->getResult(Query::HYDRATE_SINGLE_SCALAR);
    }

    /**
     * This event handler will update or insert version entities if main object's versionable properties change.
     *
     * @param \Doctrine\ORM\Event\PreFlushEventArgs $eventArgs
     */
    public function preFlush(PreFlushEventArgs $eventArgs)
    {
        $entityManager = $eventArgs->getEntityManager();
        /* @var $unitOfWork UnitOfWork */
        $unitOfWork    = $entityManager->getUnitOfWork();

        foreach ($unitOfWork->getScheduledEntityInsertions() as $object) {
            $class = get_class($object);
            $versionableMeta = $this->getExtendedMetadata($entityManager, $class);
            if (!$versionableMeta->hasVersionableProperties())
                continue;
            $meta = $entityManager->getClassMetadata($class);
            $this->updateVersions($entityManager, $meta, $versionableMeta, $object);
        }

        foreach ($unitOfWork->getIdentityMap() as $class => $entities) {
            $versionableMeta = $this->getExtendedMetadata($entityManager, $class);
            if (!$versionableMeta->hasVersionableProperties())
                continue;
            $meta = $entityManager->getClassMetadata($class);
            foreach ($entities as $object) {
                if ($object instanceof \Doctrine\ORM\Proxy\Proxy)
                    continue;
                $this->updateVersions($entityManager, $meta, $versionableMeta, $object);
            }
        }
    }

    /**
     * Get version property for specific entity
     *
     * @param \Doctrine\Common\Persistence\ObjectManager $objectManager
     * @param string $entity
     * @param \FSi\DoctrineExtensions\Versionable\Mapping\ClassMetadata $versionableMeta
     * @param string $versionEntity
     * @return string
     */
    private function getVersionProperty(ObjectManager $objectManager, $entity, VersionableClassMetadata $versionableMeta, $versionEntity)
    {
        $versionNumberField = $this->getVersionNumberField($objectManager, $versionEntity);

        $reverseFields = array_flip($versionableMeta->getVersionableProperties());
        if (isset($reverseFields[$versionNumberField]))
            return $reverseFields[$versionNumberField];
        else
            return null;
    }

    /**
     * Get status property for specific entity
     *
     * @param \Doctrine\Common\Persistence\ObjectManager $objectManager
     * @param string $entity
     * @param \FSi\DoctrineExtensions\Versionable\Mapping\ClassMetadata $versionableMeta
     * @param string $versionEntity
     * @return string
     */
    private function getStatusProperty(ObjectManager $objectManager, $entity, VersionableClassMetadata $versionableMeta, $versionEntity)
    {
        $versionStatusField = $this->getVersionStatusField($objectManager, $versionEntity);

        $reverseFields = array_flip($versionableMeta->getVersionableProperties());
        if (isset($reverseFields[$versionStatusField]))
            return $reverseFields[$versionStatusField];
        else
            return null;
    }

    /**
     * Get version field from version entity
     *
     * @param \Doctrine\Common\Persistence\ObjectManager $objectManager
     * @param $versionEntity
     * @throws Exception\MappingException
     * @return string
     */
    private function getVersionNumberField(ObjectManager $objectManager, $versionEntity)
    {
        $versionExtendedMeta = $this->getExtendedMetadata($objectManager, $versionEntity);

        if(!isset($versionExtendedMeta->versionProperty))
            throw new Exception\MappingException('Entity \''.$versionEntity.'\' seems to be a version entity so it must have field marked with @Versionable\Version annotation');

        return $versionExtendedMeta->versionProperty;
    }

    /**
     * Get status field from version entity
     *
     * @param \Doctrine\Common\Persistence\ObjectManager $objectManager
     * @param $versionEntity
     * @return string
     */
    private function getVersionStatusField(ObjectManager $objectManager, $versionEntity)
    {
        $versionExtendedMeta = $this->getExtendedMetadata($objectManager, $versionEntity);
        if (isset($versionExtendedMeta->statusProperty))
            return $versionExtendedMeta->statusProperty;
        else
            return null;
    }

    /**
     * Find version entity by specified version number using filter method from ArrayCollection class
     *
     * @param \Doctrine\Common\Collections\Collection $versions
     * @param \Doctrine\Common\Persistence\Mapping\ClassMetadata $versionMeta
     * @param string $versionNumberField
     * @param integer $currentVersionNumber
     * @return object
     * @throws Exception\RuntimeException
     */
    private function findVersion(Collection $versions, ClassMetadata $versionMeta, $versionNumberField, $currentVersionNumber)
    {
        $versions = $versions->filter(function($version) use ($currentVersionNumber, $versionMeta, $versionNumberField) {
            $versionNumber = $versionMeta->getFieldValue($version, $versionNumberField);
            if ($versionNumber === $currentVersionNumber)
                return true;
            else
                return false;
        });

        if (!$versions->count())
            return null;
        else if ($versions->count() > 1)
            throw new Exception\RuntimeException('Multiple versions with the same number has been found!');
        else
            return $versions->first();
    }
}
