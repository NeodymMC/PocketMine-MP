<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\data\bedrock\block\upgrade;

use pocketmine\data\bedrock\block\upgrade\model\BlockStateUpgradeSchemaModel;
use pocketmine\data\bedrock\block\upgrade\model\BlockStateUpgradeSchemaModelBlockRemap;
use pocketmine\data\bedrock\block\upgrade\model\BlockStateUpgradeSchemaModelTag;
use pocketmine\data\bedrock\block\upgrade\model\BlockStateUpgradeSchemaModelValueRemap;
use pocketmine\errorhandler\ErrorToExceptionHandler;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\nbt\tag\Tag;
use pocketmine\utils\Utils;
use Symfony\Component\Filesystem\Path;
use function array_map;
use function count;
use function file_get_contents;
use function get_debug_type;
use function gettype;
use function implode;
use function is_object;
use function json_decode;
use function ksort;
use function str_pad;
use function strval;
use const JSON_THROW_ON_ERROR;
use const SORT_NUMERIC;
use const STR_PAD_LEFT;

final class BlockStateUpgradeSchemaUtils{

	public static function describe(BlockStateUpgradeSchema $schema) : string{
		$lines = [];
		$lines[] = "Renames:";
		foreach($schema->renamedIds as $rename){
			$lines[] = "- $rename";
		}
		$lines[] = "Added properties:";
		foreach(Utils::stringifyKeys($schema->addedProperties) as $blockName => $tags){
			foreach(Utils::stringifyKeys($tags) as $k => $v){
				$lines[] = "- $blockName has $k added: $v";
			}
		}

		$lines[] = "Removed properties:";
		foreach(Utils::stringifyKeys($schema->removedProperties) as $blockName => $tagNames){
			foreach($tagNames as $tagName){
				$lines[] = "- $blockName has $tagName removed";
			}
		}
		$lines[] = "Renamed properties:";
		foreach(Utils::stringifyKeys($schema->renamedProperties) as $blockName => $tagNames){
			foreach(Utils::stringifyKeys($tagNames) as $oldTagName => $newTagName){
				$lines[] = "- $blockName has $oldTagName renamed to $newTagName";
			}
		}
		$lines[] = "Remapped property values:";
		foreach(Utils::stringifyKeys($schema->remappedPropertyValues) as $blockName => $remaps){
			foreach(Utils::stringifyKeys($remaps) as $tagName => $oldNewList){
				foreach($oldNewList as $oldNew){
					$lines[] = "- $blockName has $tagName value changed from $oldNew->old to $oldNew->new";
				}
			}
		}
		return implode("\n", $lines);
	}

	public static function tagToJsonModel(Tag $tag) : BlockStateUpgradeSchemaModelTag{
		$model = new BlockStateUpgradeSchemaModelTag();
		if($tag instanceof IntTag){
			$model->int = $tag->getValue();
		}elseif($tag instanceof StringTag){
			$model->string = $tag->getValue();
		}elseif($tag instanceof ByteTag){
			$model->byte = $tag->getValue();
		}else{
			throw new \UnexpectedValueException("Unexpected value type " . get_debug_type($tag));
		}

		return $model;
	}

	private static function jsonModelToTag(BlockStateUpgradeSchemaModelTag $model) : Tag{
		return match(true){
			isset($model->byte) && !isset($model->int) && !isset($model->string) => new ByteTag($model->byte),
			!isset($model->byte) && isset($model->int) && !isset($model->string) => new IntTag($model->int),
			!isset($model->byte) && !isset($model->int) && isset($model->string) => new StringTag($model->string),
			default => throw new \UnexpectedValueException("Malformed JSON model tag, expected exactly one of 'byte', 'int' or 'string' properties")
		};
	}

	public static function fromJsonModel(BlockStateUpgradeSchemaModel $model, int $priority) : BlockStateUpgradeSchema{
		$result = new BlockStateUpgradeSchema(
			$model->maxVersionMajor,
			$model->maxVersionMinor,
			$model->maxVersionPatch,
			$model->maxVersionRevision,
			$priority
		);
		$result->renamedIds = $model->renamedIds ?? [];
		$result->renamedProperties = $model->renamedProperties ?? [];
		$result->removedProperties = $model->removedProperties ?? [];

		foreach(Utils::stringifyKeys($model->addedProperties ?? []) as $blockName => $properties){
			foreach(Utils::stringifyKeys($properties) as $propertyName => $propertyValue){
				$result->addedProperties[$blockName][$propertyName] = self::jsonModelToTag($propertyValue);
			}
		}

		$convertedRemappedValuesIndex = [];
		foreach(Utils::stringifyKeys($model->remappedPropertyValuesIndex ?? []) as $mappingKey => $mappingValues){
			foreach($mappingValues as $k => $oldNew){
				$convertedRemappedValuesIndex[$mappingKey][$k] = new BlockStateUpgradeSchemaValueRemap(
					self::jsonModelToTag($oldNew->old),
					self::jsonModelToTag($oldNew->new)
				);
			}
		}

		foreach(Utils::stringifyKeys($model->remappedPropertyValues ?? []) as $blockName => $properties){
			foreach(Utils::stringifyKeys($properties) as $property => $mappedValuesKey){
				if(!isset($convertedRemappedValuesIndex[$mappedValuesKey])){
					throw new \UnexpectedValueException("Missing key from schema values index $mappedValuesKey");
				}
				$result->remappedPropertyValues[$blockName][$property] = $convertedRemappedValuesIndex[$mappedValuesKey];
			}
		}

		foreach(Utils::stringifyKeys($model->remappedStates ?? []) as $oldBlockName => $remaps){
			foreach($remaps as $remap){
				$result->remappedStates[$oldBlockName][] = new BlockStateUpgradeSchemaBlockRemap(
					array_map(fn(BlockStateUpgradeSchemaModelTag $tag) => self::jsonModelToTag($tag), $remap->oldState),
					$remap->newName,
					array_map(fn(BlockStateUpgradeSchemaModelTag $tag) => self::jsonModelToTag($tag), $remap->newState),
				);
			}
		}

		return $result;
	}

	private static function buildRemappedValuesIndex(BlockStateUpgradeSchema $schema, BlockStateUpgradeSchemaModel $model) : void{
		if(count($schema->remappedPropertyValues) === 0){
			return;
		}
		$dedupMapping = [];
		$dedupTable = [];
		$dedupTableMap = [];
		$counter = 0;

		foreach(Utils::stringifyKeys($schema->remappedPropertyValues) as $blockName => $remaps){
			foreach(Utils::stringifyKeys($remaps) as $propertyName => $remappedValues){
				$remappedValuesMap = [];
				foreach($remappedValues as $oldNew){
					$remappedValuesMap[$oldNew->old->toString()] = $oldNew;
				}

				foreach(Utils::stringifyKeys($dedupTableMap) as $dedupName => $dedupValuesMap){
					if(count($remappedValuesMap) !== count($dedupValuesMap)){
						continue;
					}

					foreach(Utils::stringifyKeys($remappedValuesMap) as $oldHash => $remappedOldNew){
						if(
							!isset($dedupValuesMap[$oldHash]) ||
							!$remappedOldNew->old->equals($dedupValuesMap[$oldHash]->old) ||
							!$remappedOldNew->new->equals($dedupValuesMap[$oldHash]->new)
						){
							continue 2;
						}
					}

					//we found a match
					$dedupMapping[$blockName][$propertyName] = $dedupName;
					continue 2;
				}

				//no match, add the values to the table
				$newDedupName = $propertyName . "_" . str_pad(strval($counter++), 2, "0", STR_PAD_LEFT);
				$dedupTableMap[$newDedupName] = $remappedValuesMap;
				$dedupTable[$newDedupName] = $remappedValues;
				$dedupMapping[$blockName][$propertyName] = $newDedupName;
			}
		}

		$modelTable = [];
		foreach(Utils::stringifyKeys($dedupTable) as $dedupName => $valuePairs){
			foreach($valuePairs as $k => $pair){
				$modelTable[$dedupName][$k] = new BlockStateUpgradeSchemaModelValueRemap(
					BlockStateUpgradeSchemaUtils::tagToJsonModel($pair->old),
					BlockStateUpgradeSchemaUtils::tagToJsonModel($pair->new),
				);
			}
		}

		$model->remappedPropertyValuesIndex = $modelTable;
		$model->remappedPropertyValues = $dedupMapping;
	}

	public static function toJsonModel(BlockStateUpgradeSchema $schema) : BlockStateUpgradeSchemaModel{
		$result = new BlockStateUpgradeSchemaModel();
		$result->maxVersionMajor = $schema->maxVersionMajor;
		$result->maxVersionMinor = $schema->maxVersionMinor;
		$result->maxVersionPatch = $schema->maxVersionPatch;
		$result->maxVersionRevision = $schema->maxVersionRevision;
		$result->renamedIds = $schema->renamedIds;
		$result->renamedProperties = $schema->renamedProperties;
		$result->removedProperties = $schema->removedProperties;

		foreach(Utils::stringifyKeys($schema->addedProperties) as $blockName => $properties){
			foreach(Utils::stringifyKeys($properties) as $propertyName => $propertyValue){
				$result->addedProperties[$blockName][$propertyName] = self::tagToJsonModel($propertyValue);
			}
		}

		self::buildRemappedValuesIndex($schema, $result);

		foreach(Utils::stringifyKeys($schema->remappedStates) as $oldBlockName => $remaps){
			foreach($remaps as $remap){
				$result->remappedStates[$oldBlockName][] = new BlockStateUpgradeSchemaModelBlockRemap(
					array_map(fn(Tag $tag) => self::tagToJsonModel($tag), $remap->oldState),
					$remap->newName,
					array_map(fn(Tag $tag) => self::tagToJsonModel($tag), $remap->newState),
				);
			}
		}

		return $result;
	}

	/**
	 * Returns a list of schemas ordered by priority. Oldest schemas appear first.
	 *
	 * @return BlockStateUpgradeSchema[]
	 */
	public static function loadSchemas(string $path, int $currentVersion) : array{
		$iterator = new \RegexIterator(
			new \FilesystemIterator(
				$path,
				\FilesystemIterator::KEY_AS_FILENAME | \FilesystemIterator::SKIP_DOTS
			),
			'/^(\d{4}).*\.json$/',
			\RegexIterator::GET_MATCH,
			\RegexIterator::USE_KEY
		);

		$result = [];

		/** @var string[] $matches */
		foreach($iterator as $matches){
			$filename = $matches[0];
			$priority = (int) $matches[1];

			$fullPath = Path::join($path, $filename);

			try{
				$raw = ErrorToExceptionHandler::trapAndRemoveFalse(fn() => file_get_contents($fullPath));
			}catch(\ErrorException $e){
				throw new \RuntimeException("Loading schema file $fullPath: " . $e->getMessage(), 0, $e);
			}

			try{
				$schema = self::loadSchemaFromString($raw, $priority);
			}catch(\RuntimeException $e){
				throw new \RuntimeException("Loading schema file $fullPath: " . $e->getMessage(), 0, $e);
			}

			if($schema->getVersionId() > $currentVersion){
				//this might be a beta schema which shouldn't be applicable
				//TODO: why do we load the whole schema just to throw it away if it's too new? ...
				continue;
			}

			$result[$priority] = $schema;
		}

		ksort($result, SORT_NUMERIC);
		return $result;
	}

	public static function loadSchemaFromString(string $raw, int $priority) : BlockStateUpgradeSchema{
		try{
			$json = json_decode($raw, false, flags: JSON_THROW_ON_ERROR);
		}catch(\JsonException $e){
			throw new \RuntimeException($e->getMessage(), 0, $e);
		}
		if(!is_object($json)){
			throw new \RuntimeException("Unexpected root type of schema file " . gettype($json) . ", expected object");
		}

		$jsonMapper = new \JsonMapper();
		try{
			$model = $jsonMapper->map($json, new BlockStateUpgradeSchemaModel());
		}catch(\JsonMapper_Exception $e){
			throw new \RuntimeException($e->getMessage(), 0, $e);
		}

		return self::fromJsonModel($model, $priority);
	}
}
