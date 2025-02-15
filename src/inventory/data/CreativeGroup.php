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

namespace pocketmine\inventory\data;

use pocketmine\inventory\CreativeCategory;
use pocketmine\item\Item;

final class CreativeGroup{
	private CreativeCategory $categoryId;
	private string $name;
	private ?Item $icon;

	public function __construct(CreativeCategory $categoryId, string $name, ?Item $icon = null){
		$this->categoryId = $categoryId;
		$this->name = $name;
		$this->icon = $icon;
	}

	public static function anonymous(CreativeCategory $categoryId) : self{
		return new self($categoryId, "");
	}

	public function getCategoryId() : CreativeCategory{
		return $this->categoryId;
	}

	public function setCategoryId(CreativeCategory $categoryId) : void{
		$this->categoryId = $categoryId;
	}

	public function getName() : string{
		return $this->name;
	}

	public function setName(string $name) : void{
		$this->name = $name;
	}

	public function getIcon() : ?Item{
		return $this->icon;
	}

	public function setIcon(?Item $icon) : void{
		$this->icon = $icon;
	}
}