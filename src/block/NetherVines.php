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

namespace pocketmine\block;

use pocketmine\block\utils\SupportType;
use pocketmine\data\runtime\RuntimeDataReader;
use pocketmine\data\runtime\RuntimeDataWriter;
use pocketmine\entity\Entity;
use pocketmine\event\block\StructureGrowEvent;
use pocketmine\item\Fertilizer;
use pocketmine\item\Item;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;
use function min;
use function mt_rand;

/**
 * This class is used for Weeping & Twisting vines, because they have same behaviour
 */
class NetherVines extends Flowable{
	public const MAX_AGE = 25;

	/** Direction the vine grows towards. */
	private int $growthFace;

	protected int $age = 0;

	public function __construct(BlockIdentifier $idInfo, string $name, BlockTypeInfo $typeInfo, int $growthFace){
		$this->growthFace = $growthFace;
		parent::__construct($idInfo, $name, $typeInfo);
	}

	public function getRequiredStateDataBits() : int{
		return 5;
	}

	public function describeState(RuntimeDataWriter|RuntimeDataReader $w) : void{
		$w->boundedInt(5, 0, self::MAX_AGE, $this->age);
	}

	public function getAge() : int{
		return $this->age;
	}

	/** @return $this */
	public function setAge(int $age) : self{
		if($age < 0 || $age > self::MAX_AGE){
			throw new \InvalidArgumentException("Age must be in range 0-" . self::MAX_AGE);
		}

		$this->age = $age;
		return $this;
	}

	public function isAffectedBySilkTouch() : bool{
		return true;
	}

	public function ticksRandomly() : bool{
		return true;
	}

	public function canClimb() : bool{
		return true;
	}

	private function getSupportFace() : int{
		return Facing::opposite($this->growthFace);
	}

	private function canBeSupportedBy(Block $block) : bool{
		return $block->getSupportType($this->getSupportFace())->hasCenterSupport() || $block->isSameType($this);
	}

	public function onNearbyBlockChange() : void{
		if(!$this->canBeSupportedBy($this->getSide($this->getSupportFace()))){
			$this->position->getWorld()->useBreakOn($this->position);
		}
	}

	/**
	 * Returns the block at the end of the vine structure furthest from the supporting block.
	 */
	private function seekToTip() : NetherVines{
		$top = $this;
		while(($next = $top->getSide($this->growthFace)) instanceof NetherVines && $next->isSameType($this)){
			$top = $next;
		}
		return $top;
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if(!$this->canBeSupportedBy($blockReplace->getSide($this->getSupportFace()))){
			return false;
		}
		$this->age = mt_rand(0, self::MAX_AGE - 1);
		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($item instanceof Fertilizer){
			if($this->grow($player, mt_rand(1, 5))){
				$item->pop();
			}
			return true;
		}
		return false;
	}

	public function onRandomTick() : void{
		if(mt_rand(1, 10) === 1 && $this->age < self::MAX_AGE){
			if($this->getSide($this->growthFace)->canBeReplaced()){
				$this->grow(null);
			}
		}
	}

	private function grow(?Player $player, int $growthAmount = 1) : bool{
		$top = $this->seekToTip();
		$age = $top->getAge();
		$pos = $top->getPosition();
		$world = $pos->getWorld();
		$changedBlocks = 0;

		$tx = new BlockTransaction($world);

		for($i = 1; $i <= $growthAmount; $i++){
			$growthPos = $pos->getSide($this->growthFace, $i);
			if(!$world->isInWorld($growthPos->getFloorX(), $growthPos->getFloorY(), $growthPos->getFloorZ()) || !$world->getBlock($growthPos)->canBeReplaced()){
				break;
			}
			$tx->addBlock($growthPos, (clone $top)->setAge(min(++$age, self::MAX_AGE)));
			$changedBlocks++;
		}

		if($changedBlocks > 0){
			$ev = new StructureGrowEvent($top, $tx, $player);
			$ev->call();

			if($ev->isCancelled()){
				return false;
			}

			return $tx->apply();
		}

		return false;
	}

	public function hasEntityCollision() : bool{
		return true;
	}

	public function onEntityInside(Entity $entity) : bool{
		$entity->resetFallDistance();
		return false;
	}

	protected function recalculateCollisionBoxes() : array{
		return [];
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		if(($item->getBlockToolType() & BlockToolType::SHEARS) !== 0 || mt_rand(1, 3) === 1){
			return [$this->asItem()];
		}
		return [];
	}

	public function getSupportType(int $facing) : SupportType{
		return SupportType::NONE();
	}
}
