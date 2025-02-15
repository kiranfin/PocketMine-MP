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

namespace pocketmine\inventory;

use pocketmine\crafting\CraftingManagerFromDataHelper;
use pocketmine\crafting\json\ItemStackData;
use pocketmine\inventory\data\CreativeGroup;
use pocketmine\inventory\json\CreativeGroupData;
use pocketmine\item\Item;
use pocketmine\item\ItemFactory;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\network\mcpe\convert\GlobalItemTypeDictionary;
use pocketmine\network\mcpe\convert\ItemTranslator;
use pocketmine\network\mcpe\convert\TypeConversionException;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\utils\DestructorCallbackTrait;
use pocketmine\utils\ObjectSet;
use pocketmine\utils\SingletonTrait;
use pocketmine\utils\Utils;
use Symfony\Component\Filesystem\Path;
use function array_filter;
use function array_map;

final class CreativeInventory{
	use SingletonTrait;
	use DestructorCallbackTrait;

	/**
	 * @var CreativeGroup[]
	 * @phpstan-var array<int, CreativeGroup>
	 */
	private array $groups = [];

	/**
	 * @var Item[]
	 * @phpstan-var array<int, Item>
	 */
	private array $items = [];

	/** @phpstan-var ObjectSet<\Closure() : void> */
	private ObjectSet $contentChangedCallbacks;

	private CreativeGroup $defaultGroup;

	private function __construct(){
		$this->contentChangedCallbacks = new ObjectSet();
		$this->defaultGroup = CreativeGroup::anonymous(CreativeCategory::ITEMS);

		foreach([
			"construction" => CreativeCategory::CONSTRUCTION,
			"nature" => CreativeCategory::NATURE,
			"equipment" => CreativeCategory::EQUIPMENT,
			"items" => CreativeCategory::ITEMS,
		] as $category => $categoryId){
			$groups = CraftingManagerFromDataHelper::loadJsonArrayOfObjectsFile(
				Path::join(\pocketmine\BEDROCK_DATA_PATH, "creative", $category . ".json"),
				CreativeGroupData::class
			);


			foreach($groups as $groupData){
				$icon = null;
				if($groupData->group_icon !== null){
					$icon = self::deserializeItemStackData($groupData->group_icon);
				}
				$group = new CreativeGroup(
					$categoryId,
					$groupData->group_name,
					$icon
				);
				$items = array_filter(array_map(static fn($itemStack) => self::deserializeItemStackData($itemStack), $groupData->items));

				foreach($items as $item){
					$this->add($item, $group);
				}
			}
		}
	}

	private static function deserializeItemStackData(ItemStackData $data) : ?Item{
		try{
			$intId = GlobalItemTypeDictionary::getInstance()->getDictionary()->fromStringId($data->name);
			[$id, $meta] = ItemTranslator::getInstance()->fromNetworkId($intId, $data->meta ?? 0);
			$item = ItemFactory::getInstance()->get($id, $meta);
			if(isset($data->nbt)){
				$item->setNamedTag((new LittleEndianNbtSerializer())->read(Utils::assumeNotFalse(base64_decode($data->nbt, true)))->mustGetCompoundTag());
			}
			if(isset($data->count)){
				$item->setCount($data->count);
			}
			return $item;
		}catch(\InvalidArgumentException|TypeConversionException){
			return null;
		}
	}

	/**
	 * Removes all previously added items from the creative menu.
	 * Note: Players who are already online when this is called will not see this change.
	 */
	public function clear() : void{
		$this->groups = [];
		$this->items = [];
		$this->onContentChange();
	}

	/**
	 * @return Item[]
	 * @phpstan-return array<int, Item>
	 */
	public function getAll() : array{
		return Utils::cloneObjectArray($this->items);
	}

	/**
	 * @return CreativeGroup[]
	 * @phpstan-return array<int, CreativeGroup>
	 */
	public function getItemGroup() : array{
		return $this->groups;
	}

	public function getItem(int $index) : ?Item{
		return isset($this->items[$index]) ? clone $this->items[$index] : null;
	}

	public function getGroup(int $index) : ?CreativeGroup{
		return $this->groups[$index] ?? null;
	}

	public function getItemIndex(Item $item) : int{
		foreach($this->items as $i => $d){
			if($item->equals($d, true, false)){
				return $i;
			}
		}

		return -1;
	}

	/**
	 * Adds an item to the creative menu.
	 * Note: Players who are already online when this is called will not see this change.
	 */
	public function add(Item $item, ?CreativeGroup $group = null) : void{
		$this->items[] = $item;
		$this->groups[] = $group ?? $this->defaultGroup;
		$this->onContentChange();

		if($group !== null){ // We need to create a new default group if another group is used.
			$this->defaultGroup = CreativeGroup::anonymous(CreativeCategory::ITEMS);
		}
	}

	/**
	 * Removes an item from the creative menu.
	 * Note: Players who are already online when this is called will not see this change.
	 */
	public function remove(Item $item) : void{
		$index = $this->getItemIndex($item);
		if($index !== -1){
			unset($this->items[$index]);
			unset($this->groups[$index]);
			$this->onContentChange();
		}
	}

	public function contains(Item $item) : bool{
		return $this->getItemIndex($item) !== -1;
	}

	/** @phpstan-return ObjectSet<\Closure() : void> */
	public function getContentChangedCallbacks() : ObjectSet{
		return $this->contentChangedCallbacks;
	}

	private function onContentChange() : void{
		foreach($this->contentChangedCallbacks as $callback){
			$callback();
		}
	}
}