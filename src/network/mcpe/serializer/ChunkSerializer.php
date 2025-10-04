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

namespace pocketmine\network\mcpe\serializer;

use pmmp\encoding\Byte;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\VarInt;
use pocketmine\block\tile\Spawnable;
use pocketmine\data\bedrock\BiomeIds;
use pocketmine\data\bedrock\LegacyBiomeIdToStringIdMap;
use pocketmine\nbt\TreeRoot;
use pocketmine\network\mcpe\convert\RuntimeBlockMapping;
use pocketmine\network\mcpe\protocol\serializer\NetworkNbtSerializer;
use pocketmine\utils\Binary;
use pocketmine\utils\BinaryStream;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\PalettedBlockArray;
use pocketmine\world\format\SubChunk;
use function chr;
use function count;
use function str_repeat;

final class ChunkSerializer{
	public const LOWER_PADDING_SIZE = 4;

	private function __construct(){
		//NOOP
	}

	/**
	 * Returns the number of subchunks that will be sent from the given chunk.
	 * Chunks are sent in a stack, so every chunk below the top non-empty one must be sent.
	 */
	public static function getSubChunkCount(Chunk $chunk) : int{
		for($y = Chunk::MAX_SUBCHUNK_INDEX, $count = count($chunk->getSubChunks()); $y >= Chunk::MIN_SUBCHUNK_INDEX; --$y, --$count){
			if($chunk->getSubChunk($y)->isEmptyFast()){
				continue;
			}
			return $count;
		}

		return 0;
	}

	public static function serializeFullChunk(Chunk $chunk, RuntimeBlockMapping $blockMapper, ?string $tiles = null) : string{
		$stream = new ByteBufferWriter();

		//TODO: HACK! fill in fake subchunks to make up for the new negative space client-side
		for($y = 0; $y < self::LOWER_PADDING_SIZE; $y++){
			Byte::writeUnsigned($stream, 8); //subchunk version 8
			Byte::writeUnsigned($stream, 0); //0 layers - client will treat this as all-air
		}

		$subChunkCount = self::getSubChunkCount($chunk);
		for($y = Chunk::MIN_SUBCHUNK_INDEX, $writtenCount = 0; $writtenCount < $subChunkCount; ++$y, ++$writtenCount){
			self::serializeSubChunk($chunk->getSubChunk($y), $blockMapper, $stream, false);
		}

		//TODO: right now we don't support 3D natively, so we just 3Dify our 2D biomes so they fill the column
		$encodedBiomePalette = self::serializeBiomesAsPalette($chunk);
		$stream->writeByteArray(str_repeat($encodedBiomePalette, 24));

		Byte::writeUnsigned($stream, 0); //border block array count
		//Border block entry format: 1 byte (4 bits X, 4 bits Z). These are however useless since they crash the regular client.

		if($tiles !== null){
			$stream->writeByteArray($tiles);
		}else{
			$stream->writeByteArray(self::serializeTiles($chunk));
		}
		return $stream->getData();
	}

	public static function serializeSubChunk(SubChunk $subChunk, RuntimeBlockMapping $blockMapper, ByteBufferWriter $stream, bool $persistentBlockStates) : void{
		$layers = $subChunk->getBlockLayers();
		Byte::writeUnsigned($stream, 8); //version

		Byte::writeUnsigned($stream, count($layers));

		foreach($layers as $blocks){
			$bitsPerBlock = $blocks->getBitsPerBlock();
			$words = $blocks->getWordArray();
			Byte::writeUnsigned($stream, ($bitsPerBlock << 1) | ($persistentBlockStates ? 0 : 1));
			$stream->writeByteArray($words);
			$palette = $blocks->getPalette();

			if($bitsPerBlock !== 0){
				VarInt::writeSignedInt($stream, count($palette)); //yes, this is intentionally zigzag
			}
			if($persistentBlockStates){
				$nbtSerializer = new NetworkNbtSerializer();
				foreach($palette as $p){
					$stream->writeByteArray($nbtSerializer->write(new TreeRoot($blockMapper->getBedrockKnownStates()[$blockMapper->toRuntimeId($p)])));
				}
			}else{
				foreach($palette as $p){
					VarInt::writeSignedInt($stream, $blockMapper->toRuntimeId($p));

				}
			}
		}
	}

	public static function serializeTiles(Chunk $chunk) : string{
		$stream = new ByteBufferWriter();
		foreach($chunk->getTiles() as $tile){
			if($tile instanceof Spawnable){
				$stream->writeByteArray($tile->getSerializedSpawnCompound()->getEncodedNbt());
			}
		}

		return $stream->getData();
	}

	private static function serializeBiomesAsPalette(Chunk $chunk) : string{
		$biomeIdMap = LegacyBiomeIdToStringIdMap::getInstance();
		$biomePalette = new PalettedBlockArray($chunk->getBiomeId(0, 0));
		for($x = 0; $x < 16; ++$x){
			for($z = 0; $z < 16; ++$z){
				$biomeId = $chunk->getBiomeId($x, $z);
				if($biomeIdMap->legacyToString($biomeId) === null){
					//make sure we aren't sending bogus biomes - the 1.18.0 client crashes if we do this
					$biomeId = BiomeIds::OCEAN;
				}
				for($y = 0; $y < 16; ++$y){
					$biomePalette->set($x, $y, $z, $biomeId);
				}
			}
		}

		$biomePaletteBitsPerBlock = $biomePalette->getBitsPerBlock();
		$encodedBiomePalette =
			chr(($biomePaletteBitsPerBlock << 1) | 1) . //the last bit is non-persistence (like for blocks), though it has no effect on biomes since they always use integer IDs
			$biomePalette->getWordArray();

		//these LSHIFT by 1 uvarints are optimizations: the client expects zigzag varints here
		//but since we know they are always unsigned, we can avoid the extra fcall overhead of
		//zigzag and just shift directly.
		$biomePaletteArray = $biomePalette->getPalette();
		if($biomePaletteBitsPerBlock !== 0){
			$encodedBiomePalette .= Binary::writeUnsignedVarInt(count($biomePaletteArray) << 1);
		}
		foreach($biomePaletteArray as $p){
			$encodedBiomePalette .= Binary::writeUnsignedVarInt($p << 1);
		}

		return $encodedBiomePalette;
	}
}
