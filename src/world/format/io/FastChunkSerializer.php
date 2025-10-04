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

namespace pocketmine\world\format\io;

use pmmp\encoding\BE;
use pmmp\encoding\Byte;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pocketmine\world\format\BiomeArray;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\PalettedBlockArray;
use pocketmine\world\format\SubChunk;
use function array_values;
use function count;
use function pack;
use function strlen;
use function unpack;

/**
 * This class provides a serializer used for transmitting chunks between threads.
 * The serialization format **is not intended for permanent storage** and may change without warning.
 */
final class FastChunkSerializer{
	private const FLAG_POPULATED = 1 << 1;

	private function __construct(){
		//NOOP
	}

	/**
	 * Fast-serializes the chunk for passing between threads
	 * TODO: tiles and entities
	 */
	public static function serializeTerrain(Chunk $chunk) : string{
		$stream = new ByteBufferWriter();
		Byte::writeUnsigned($stream, ($chunk->isPopulated() ? self::FLAG_POPULATED : 0));


		//subchunks
		$subChunks = $chunk->getSubChunks();
		$count = count($subChunks);
		Byte::writeUnsigned($stream, $count);

		foreach($subChunks as $y => $subChunk){
			Byte::writeSigned($stream, $y);
			BE::writeUnsignedInt($stream, $subChunk->getEmptyBlockId());
			$layers = $subChunk->getBlockLayers();
			Byte::writeUnsigned($stream, count($layers));
			foreach($layers as $blocks){
				$wordArray = $blocks->getWordArray();
				$palette = $blocks->getPalette();

				Byte::writeUnsigned($stream, $blocks->getBitsPerBlock());
				$stream->writeByteArray($wordArray);
				$serialPalette = pack("L*", ...$palette);
				BE::writeUnsignedInt($stream, strlen($serialPalette));
				$stream->writeByteArray($serialPalette);
			}
		}

		//biomes
		$stream->writeByteArray($chunk->getBiomeIdArray());

		return $stream->getData();
	}

	/**
	 * Deserializes a fast-serialized chunk
	 */
	public static function deserializeTerrain(string $data) : Chunk{
		$stream = new ByteBufferReader($data);

		$flags = Byte::readUnsigned($stream);
		$terrainPopulated = (bool) ($flags & self::FLAG_POPULATED);

		$subChunks = [];

		$count = Byte::readUnsigned($stream);
		for($subCount = 0; $subCount < $count; ++$subCount){
			$y = Byte::readSigned($stream);
			$airBlockId = BE::readUnsignedInt($stream);

			/** @var PalettedBlockArray[] $layers */
			$layers = [];
			for($i = 0, $layerCount = Byte::readUnsigned($stream); $i < $layerCount; ++$i){
				$bitsPerBlock = Byte::readUnsigned($stream);
				$words = $stream->readByteArray(PalettedBlockArray::getExpectedWordArraySize($bitsPerBlock));
				$paletteSize = BE::readUnsignedInt($stream);
				/** @var int[] $unpackedPalette */
				$unpackedPalette = unpack("L*", $stream->readByteArray($paletteSize)); //unpack() will never fail here
				$palette = array_values($unpackedPalette);

				$layers[] = PalettedBlockArray::fromData($bitsPerBlock, $words, $palette);
			}
			$subChunks[$y] = new SubChunk($airBlockId, $layers);
		}

		$biomeIds = new BiomeArray($stream->readByteArray(256));

		return new Chunk($subChunks, $biomeIds, $terrainPopulated);
	}
}
