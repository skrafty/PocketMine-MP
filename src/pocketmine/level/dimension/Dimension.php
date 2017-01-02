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

declare(strict_types = 1);

namespace pocketmine\level\dimension;

use pocketmine\entity\Entity;
use pocketmine\event\block\BlockUpdateEvent;
use pocketmine\level\dimension\vanilla\{
	Overworld,
	Nether,
	TheEnd
};
use pocketmine\level\ChunkManager;
use pocketmine\level\format\Chunk;
use pocketmine\level\format\generic\GenericChunk;
use pocketmine\level\generator\{
	Generator,
	GeneratorRegisterTask,
	GeneratorUnregisterTask
};
use pocketmine\level\Level;
use pocketmine\math\Vector3;
use pocketmine\network\protocol\{
	ChangeDimensionPacket,
	DataPacket,
	LevelEventPacket
};
use pocketmine\Player;
use pocketmine\Server;
use pocketmine\tile\Tile;
use pocketmine\utils\Random;

/**
 * Base Dimension class
 * @since API 3.0.0
 */
abstract class Dimension implements ChunkManager{

	const ID_RESERVED = -1;
	const ID_OVERWORLD = 0;
	const ID_NETHER = 1;
	const ID_THE_END = 2;

	private static $registeredDimensions = [];
	private static $saveIdCounter = 1000;

	/**
	 * Initializes default dimension list on server startup.
	 * @internal
	 */
	public static function init(){
		DimensionType::init();
		self::$registeredDimensions = [
			Dimension::ID_OVERWORLD => [Overworld::class, ChangeDimensionPacket::DIMENSION_OVERWORLD],
			Dimension::ID_NETHER    => [Nether::class,    ChangeDimensionPacket::DIMENSION_NETHER],
			Dimension::ID_THE_END   => [TheEnd::class,    ChangeDimensionPacket::DIMENSION_THE_END]
		];
	}

	/**
	 * Registers a custom dimension class to an ID.
	 * @since API 3.0.0
	 *
	 * @param string $dimension the fully qualified class name of the dimension class
	 * @param int    $networkId the default network ID to use when creating an instance of this dimension. Affects sky colour. Should be a constant from {@link \pocketmine\network\protocol\ChangeDimensionPacket}
	 * @param int    $saveId the requested dimension ID. If not supplied, a new one will be automatically assigned.
	 * @param bool   $overrideExisting whether to override the dimension registered to the ID requested if it's already in use.
	 *
	 * @return int|bool the assigned ID if registration was successful, false if not.
	 */
	public static function registerDimension(string $dimension, int $networkId = ChangeDimensionPacket::DIMENSION_OVERWORLD, int $saveId = Dimension::ID_RESERVED, bool $overrideExisting = false){
		if($saveId === Dimension::ID_RESERVED){
			$saveId = self::$saveIdCounter;
		}elseif(isset(self::$registeredDimensions[$saveId]) and !$overrideExisting){
			return false;
		}

		$class = new \ReflectionClass($dimension);
		if($class->isSubclassOf(Dimension::class) and $class->implementsInterface(CustomDimension::class) and !$class->isAbstract()){
			self::$registeredDimensions[$saveId] = [$dimension, $networkId];
			self::$saveIdCounter = max(self::$saveIdCounter, $saveId + 1);
			return $saveId;
		}

		return false;
	}

	/**
	 * Creates a dimension for the specified level with the specified dimension ID.
	 * @internal
	 *
	 * @param Level $level the parent level
	 * @param int   $saveId the ID of the dimension to create
	 *
	 * @return Dimension|null
	 */
	public static function createDimension(Level $level, int $saveId){
		if(isset(self::$registeredDimensions[$saveId]) and $saveId !== Dimension::ID_RESERVED){
			$class = self::$registeredDimensions[$saveId][0];
			try{
				return new $class($level, $saveId, self::$registeredDimensions[$saveId][1]);
			}catch(\Throwable $e){
				$level->getServer()->getLogger()->logException($e);
				return null;
			}
		}

		return null;
	}

	/** @var Level */
	protected $level;
	/** @var DimensionType */
	protected $dimensionType;
	/** @var int */
	protected $saveId;
	/** @var string */
	protected $generatorClass;

	/** @var Chunk[] */
	protected $chunks = [];

	/** @var DataPacket[] */
	protected $chunkCache = [];

	/** @var DataPacket[] */
	protected $chunkPackets = [];

	/** @var Block[] */
	protected $blockCache = [];

	/** @var Player[] */
	protected $players = [];

	/** @var Entity[] */
	protected $entities = [];
	/** @var Tile[] */
	protected $tiles = [];

	/** @var Entity[] */
	public $updateEntities = [];
	/** @var Tile[] */
	public $updateTiles = [];

	protected $motionToSend = [];
	protected $moveToSend = [];

	protected $rainLevel = 0;
	protected $thunderLevel = 0;
	protected $nextWeatherChange;

	/**
	 * @internal
	 *
	 * @param Level  $level
	 * @param int    $saveId
	 * @param int    $networkId
	 * @param string $generatorName
	 *
	 * @throws \InvalidArgumentException
	 */
	protected function __construct(Level $level, int $saveId, int $networkId = ChangeDimensionPacket::DIMENSION_OVERWORLD, string $generatorName = "DEFAULT"){
		$this->level = $level;
		$this->saveId = $saveId;
		if(($type = DimensionType::get($networkId)) instanceof DimensionType){
			$this->dimensionType = $type;
		}else{
			throw new \InvalidArgumentException("Unknown dimension network ID $networkId given");
		}

		$this->generatorClass = Generator::getGenerator($generatorName);
		$generator = $this->generatorClass;
		$this->generatorInstance = new $generator($this->level->getProvider()->getGeneratorOptions());
		$this->generatorInstance->init($this, new Random($this->level->getSeed()));
		$this->registerGenerator();
	}

	/**
	 * @since API 3.0.0
	 *
	 * @return Level
	 */
	final public function getLevel() : Level{
		return $this->level;
	}

	/**
	 * Returns a DimensionType object containing immutable dimension properties
	 * @since API 3.0.0
	 *
	 * @return DimensionType
	 */
	final public function getDimensionType() : DimensionType{
		return $this->dimensionType;
	}

	/**
	 * Returns the dimension's ID. Unique within levels only. This is used for world saves.
	 *
	 * NOTE: For vanilla dimensions, this will NOT match the folder name in Anvil/MCRegion formats due to inconsistencies in dimension
	 * IDs between PC and PE. For example the Nether has saveID 1, but will be saved in the DIM-1 folder in the world save.
	 *
	 * @since API 3.0.0
	 *
	 * @return int
	 */
	final public function getSaveId() : int{
		return $this->saveId;
	}

	/**
	 * Returns the dimension's network ID based on the dimension type. This value affects sky colours seen by clients (red, blue, purple)
	 * @since API 3.0.0
	 *
	 * @return int
	 */
	final public function getNetworkId() : int{
		return $this->dimensionType->getNetworkId();
	}

	/**
	 * Returns the display name of this dimension.
	 * @since API 3.0.0
	 *
	 * @return string
	 */
	abstract public function getDimensionName() : string;

	/**
	 * Returns the fully qualified class name of the generator used for this dimension.
	 * @since API 3.0.0
	 *
	 * @return string
	 */
	public function getGenerator() : string{
		return $this->generatorClass;
	}

	/**
	 * Registers the generator to all AsyncWorkers for async chunk generation.
	 * @internal
	 */
	public function registerGenerator(){
		$size = $this->level->getServer()->getScheduler()->getAsyncTaskPoolSize();
		for($i = 0; $i < $size; ++$i){
			$this->level->getServer()->getScheduler()->scheduleAsyncTaskToWorker(new GeneratorRegisterTask($this, $this->generatorInstance), $i);
		}
	}

	/**
	 * Unregisters the generator from all AsyncWorkers
	 * @internal
	 */
	public function unregisterGenerator(){
		$size = $this->level->getServer()->getScheduler()->getAsyncTaskPoolSize();
		for($i = 0; $i < $size; ++$i){
			$this->level->getServer()->getScheduler()->scheduleAsyncTaskToWorker(new GeneratorUnregisterTask($this), $i);
		}
	}

	/**
	 * Returns the horizontal (X/Z) of 1 block in this dimension compared to the Overworld.
	 * This is used to calculate positions for entities transported between dimensions.
	 *
	 * @since API 3.0.0
	 *
	 * @return float
	 */
	public function getDistanceMultiplier() : float{
		return 1.0;
	}

	/**
	 * Returns the dimension max build height as per MCPE
	 * @since API 3.0.0
	 *
	 * @return int
	 */
	final public function getMaxBuildHeight() : int{
		//TODO: add world/dimension height options (?)
		return $this->dimensionType->getMaxBuildHeight();
	}

	/**
	 * Returns all players in the dimension.
	 * @since API 3.0.0
	 *
	 * @return Player[]
	 */
	public function getPlayers() : array{
		return $this->players;
	}

	/**
	 * Returns whether there are currently players in this dimension or not.
	 * @since API 3.0.0
	 *
	 * @return bool
	 */
	public function isInUse() : bool{
		return count($this->players) > 0;
	}

	/**
	 * Returns players in the specified chunk.
	 * @since API 3.0.0
	 *
	 * @param int $chunkX
	 * @param int $chunkZ
	 *
	 * @return Player[]
	 */
	public function getChunkPlayers(int $chunkX, int $chunkZ) : array{
		return $this->playerLoaders[Level::chunkHash($chunkX, $chunkZ)] ?? [];
	}

	/**
	 * Returns chunk loaders using the specified chunk
	 * @since API 3.0.0
	 *
	 * @param int $chunkX
	 * @param int $chunkZ
	 *
	 * @return ChunkLoader[]
	 */
	public function getChunkLoaders(int $chunkX, int $chunkZ) : array{
		return $this->chunkLoaders[Level::chunkHash($chunkX, $chunkZ)] ?? [];
	}

	/**
	 * Adds an entity to the dimension index.
	 * @since API 3.0.0
	 *
	 * @param Entity $entity
	 */
	public function addEntity(Entity $entity){
		if($entity instanceof Player){
			$this->players[$entity->getId()] = $entity;
		}
		$this->entities[$entity->getId()] = $entity;
	}

	/**
	 * Removes an entity from the dimension's entity index. We do NOT close the entity here as it may simply be getting transferred
	 * to another dimension.
	 *
	 * @since API 3.0.0
	 *
	 * @param Entity $entity
	 */
	public function removeEntity(Entity $entity){
		if($entity instanceof Player){
			unset($this->players[$entity->getId()]);
			$this->checkSleep();
		}

		unset($this->entities[$entity->getId()]);
		unset($this->updateEntities[$entity->getId()]);
	}

	/**
	 * Transfers an entity to this dimension from a different one.
	 * @since API 3.0.0
	 *
	 * @param Entity $entity
	 */
	public function transferEntity(Entity $entity){
		//TODO
	}

	/**
	 * Returns all entities in the dimension.
	 * @since API 3.0.0
	 *
	 * @return Entity[]
	 */
	public function getEntities() : array{
		return $this->entities;
	}

	/**
	 * Returns the entity with the specified ID in this dimension, or null if it does not exist.
	 * @since API 3.0.0
	 *
	 * @param int $entityId
	 *
	 * @return Entity|null
	 */
	public function getEntity(int $entityId){
		return $this->entities[$entityId] ?? null;
	}

	/**
	 * Returns a list of the entities in the specified chunk. Returns an empty array if the chunk is not loaded.
	 * @since API 3.0.0
	 *
	 * @param int $X
	 * @param int $Z
	 *
	 * @return Entity[]
	 */
	public function getChunkEntities(int $X, int $Z) : array{
		return ($chunk = $this->getChunk($X, $Z)) !== null ? $chunk->getEntities() : [];
	}

	/**
	 * Adds a tile to the dimension index.
	 * @since API 3.0.0
	 *
	 * @param Tile $tile
	 *
	 * @throws LevelException
	 */
	public function addTile(Tile $tile){
		/*if($tile->getLevel() !== $this){
			throw new LevelException("Invalid Tile level");
		}*/
		$this->tiles[$tile->getId()] = $tile;
		$this->clearChunkCache($tile->getX() >> 4, $tile->getZ() >> 4);
	}

	/**
	 * Removes a tile from the dimension index.
	 * @since API 3.0.0
	 *
	 * @param Tile $tile
	 *
	 * @throws LevelException
	 */
	public function removeTile(Tile $tile){
		/*if($tile->getLevel() !== $this){
			throw new LevelException("Invalid Tile level");
		}*/

		unset($this->tiles[$tile->getId()]);
		unset($this->updateTiles[$tile->getId()]);
		$this->clearChunkCache($tile->getX() >> 4, $tile->getZ() >> 4);
	}

	/**
	 * Returns a list of the Tiles in this dimension
	 * @since API 3.0.0
	 *
	 * @return Tile[]
	 */
	public function getTiles() : array{
		return $this->tiles;
	}

	/**
	 * Returns the tile with the specified ID in this dimension
	 * @since API 3.0.0
	 *
	 * @param $tileId
	 *
	 * @return Tile|null
	 */
	public function getTileById(int $tileId){
		return $this->tiles[$tileId] ?? null;
	}

	/**
	 * Returns the Tile at the specified position, or null if not found
	 * @since API 3.0.0
	 *
	 * @param Vector3 $pos
	 *
	 * @return Tile|null
	 */
	public function getTile(Vector3 $pos){
		$chunk = $this->getChunk($pos->x >> 4, $pos->z >> 4, false);

		if($chunk !== null){
			return $chunk->getTile($pos->x & 0x0f, $pos->y, $pos->z & 0x0f);
		}

		return null;
	}

	/**
	 * Gives a list of the Tile entities in the specified chunk. Returns an empty array if the chunk is not loaded.
	 * @since API 3.0.0
	 *
	 * @param int $X
	 * @param int $Z
	 *
	 * @return Tile[]
	 */
	public function getChunkTiles(int $X, int $Z) : array{
		return ($chunk = $this->getChunk($X, $Z)) !== null ? $chunk->getTiles() : [];
	}

	/**
	 * Returns an array of currently loaded chunks.
	 * @since API 3.0.0
	 *
	 * @return Chunk[]
	 */
	public function getChunks() : array{
		return $this->chunks;
	}

	/**
	 * Returns the chunk at the specified index, or null if it does not exist and has not been generated
	 * @since API 3.0.0
	 *
	 * @param int  $chunkX
	 * @param int  $chunkZ
	 * @param bool $generate whether to generate the chunk if it does not exist.
	 *
	 * @return Chunk|null
	 */
	public function getChunk(int $chunkX, int $chunkZ, bool $generate = false){
		//TODO: alter this to handle asynchronous chunk loading
		if(isset($this->chunks[$index = Level::chunkHash($chunkX, $chunkZ)])){
			return $this->chunks[$index];
		}elseif($this->loadChunk($chunkX, $chunkZ, $generate)){
			return $this->chunks[$index];
		}

		return null;
	}

	public function setChunk(int $chunkX, int $chunkZ, Chunk $chunk = null){
		//TODO
	}

	/**
	 * Returns the block at the specified Vector3 position in this dimension
	 * @since API 3.0.0
	 *
	 * @param Vector3 $pos
	 * @param bool    $useCache
	 * @param bool    $shouldCache
	 *
	 * @return Block
	 */
	public function getBlock(Vector3 $pos, bool $useCache = true, bool $shouldCache = true) : Block{
		$pos = $pos->floor();
		return $this->getBlockAt($pos->x, $pos->y, $pos->z, $useCache, $shouldCache);
	}

	/**
	 * Returns the block at the specified coordinates
	 * @since API 3.0.0
	 *
	 * @param int  $x
	 * @param int  $y
	 * @param int  $z
	 * @param bool $useCache
	 * @param bool $shouldCache Whether to cache the created block object if it is not already
	 *
	 * @return Block
	 */
	public function getBlockAt(int $x, int $y, int $z, bool $useCache = true, bool $shouldCache = true) : Block{
		if($y < 0 or $y > $this->getMaxBuildHeight()){
			return clone Block::$fullList[0]; //outside Y coordinate range, don't try to hash coords or bother caching
		}

		$fullState = 0; //Default to air

		$index = Level::blockHash($x, $y, $z);
		if($useCache and isset($this->blockCache[$index])){
			return $this->blockCache[$index];
		}elseif(isset($this->chunks[$chunkIndex = Level::chunkHash($pos->x >> 4, $pos->z >> 4)])){
			$fullState = $this->chunks[$chunkIndex]->getFullBlock($x & 0x0f, $y, $z & 0x0f);
		}

		$block = clone Block::$fullList[$fullState & 0xfff];

		$block->x = $x;
		$block->y = $y;
		$block->z = $z;
		$block->level = $this->level;
		$block->dimensionId = $this->saveId;

		if($shouldCache){
			assert(count($this->blockCache) <= 2048, "Block cache exceeded 2048 entries, is something abusing getBlock()?");
			$this->blockCache[$index] = $block;
		}

		return $block;
	}

	/**
	 * Returns an integer bitmap containing the id and damage of the block at the specified position.
	 * @since API 3.0.0
	 *
	 * @param int $x
	 * @param int $y
	 * @param int $z
	 *
	 * @return int bitmap, (id << 4) | data
	 */
	public function getFullBlock(int $x, int $y, int $z) : int{
		return $this->getChunk($x >> 4, $z >> 4, false)->getFullBlock($x & 0x0f, $y, $z & 0x0f);
	}

	/**
	 * Wrapper for setBlockAt() which accepts a Vector3 parameter for the position.
	 * @since API 3.0.0
	 *
	 * @param Vector3 $pos
	 * @param Block   $block
	 * @param bool    $direct @deprecated
	 * @param bool    $update
	 *
	 * @return bool
	 */
	public function setBlock(Vector3 $pos, Block $block, bool $direct = false, bool $update = true) : bool{
		$pos = $pos->floor();
		return $this->setBlockAt($pos->x, $pos->y, $pos->z, $block, $direct, $update);
	}

	/**
	 * Sets data from a Block object to the specified position, does block updates and adds the changes to the send queue.
	 *
	 * If $direct is true, it'll send changes directly to players. if false, it'll be queued
	 * and the best way to send queued changes will be done in the next tick.
	 * This way big changes can be sent in a single chunk update packet instead of thousands of packets.
	 *
	 * If $update is true, it'll get the neighbour blocks (6 sides) and update them.
	 * If you are doing big changes, you might want to set this to false, then update manually.
	 *
	 * @since API 3.0.0
	 *
	 * @param int   $x
	 * @param int   $y
	 * @param int   $z
	 * @param Block $block
	 * @param bool  $direct @deprecated
	 * @param bool  $update
	 *
	 * @return bool if changes were made
	 */
	public function setBlockAt(int $x, int $y, int $z, Block $block, bool $direct = false, bool $update = true) : bool{
		if($y < 0 or $y > $this->getMaxBuildHeight()){
			return false; //outside Y coordinate range
		}

		if($this->getChunk($x >> 4, $z >> 4, true)->setBlock($x & 0x0f, $y, $z & 0x0f, $block->getId(), $block->getDamage()) === true){
			$block->x = $x;
			$block->y = $y;
			$block->z = $z;
			$block->level = $this->level;
			$block->dimensionId = $this->saveId;

			unset($this->blockCache[$blockHash = Level::blockHash($x, $y, $z)]);

			$chunkHash = Level::chunkHash($x >> 4, $z >> 4);

			if($direct === true){
				$this->sendBlocks($this->getChunkPlayers($x >> 4, $z >> 4), [$block], UpdateBlockPacket::FLAG_ALL_PRIORITY);
				unset($this->chunkCache[$chunkHash]);
			}else{
				if(!isset($this->changedBlocks[$chunkHash])){
					$this->changedBlocks[$chunkHash] = [];
				}

				$this->changedBlocks[$chunkHash][$blockHash] = clone $block;
			}

			foreach($this->getChunkLoaders($x >> 4, $z >> 4) as $loader){
				$loader->onBlockChanged($block);
			}

			if($update === true){
				$this->updateAllLight($block);

				$this->level->getServer()->getPluginManager()->callEvent($ev = new BlockUpdateEvent($block));
				if(!$ev->isCancelled()){
					$evBlock = $ev->getBlock();
					foreach($this->getNearbyEntities(new AxisAlignedBB($evBlock->x - 1, $evBlock->y - 1, $evBlock->z - 1, $evBlock->x + 1, $evBlock->y + 1, $evBlock->z + 1)) as $entity){
						$entity->scheduleUpdate();
					}
					$evBlock->onUpdate(self::BLOCK_UPDATE_NORMAL);
					$this->updateBlocksAround($evBlock);
				}
			}

			return true;
		}

		return false;
	}

	/**
	 * Returns the maximum lighting available at the specified Vector3 position.
	 * @since API 3.0.0
	 *
	 * @param Vector3 $pos
	 *
	 * @return int 0-15
	 */
	public function getFullLight(Vector3 $pos) : int{
		$pos = $pos->floor();
		return $this->getFullLightAt($pos->x, $pos->y, $pos->z);
	}

	/**
	 * Returns the maximum available lighting at the specified coordinates.
	 * @since API 3.0.0
	 *
	 * @param int $x
	 * @param int $y
	 * @param int $z
	 *
	 * @return int 0-15
	 */
	public function getFullLightAt(int $x, int $y, int $z) : int{
		$chunk = $this->getChunk($x >> 4, $z >> 4, false);
		if($chunk !== null){
			if($this->dimensionType->hasSkyLight()){
				return max($chunk->getBlockSkyLight($x & 0x0f, $y, $z & 0x0f), $chunk->getBlockLight($x & 0x0f, $y, $z & 0x0f));
			}else{
				return $chunk->getBlockLight($x & 0x0f, $y, $z & 0x0f);
			}
		}

		return $this->dimensionType->hasSkyLight() ? 15 : 0;
	}

	/**
	 * Returns the ID of the block at the specified coordinates.
	 * @since API 3.0.0
	 *
	 * @param int $x
	 * @param int $y
	 * @param int $z
	 *
	 * @return int 0-255
	 */
	public function getBlockIdAt(int $x, int $y, int $z) : int{
		return $this->getChunk($x >> 4, $z >> 4, true)->getBlockId($x & 0x0f, $y, $z & 0x0f);
	}

	/**
	 * Sets the raw block ID at the specified coordinates.
	 * @since API 3.0.0
	 *
	 * @param int $x
	 * @param int $y
	 * @param int $z
	 * @param int $id 0-255
	 */
	public function setBlockIdAt(int $x, int $y, int $z, int $id){
		$this->getChunk($x >> 4, $z >> 4, true)->setBlockId($x & 0x0f, $y, $z & 0x0f, $id & 0xff);
		unset($this->blockCache[Level::blockHash($x, $y, $z)]);

		if(!isset($this->changedBlocks[$index = Level::chunkHash($x >> 4, $z >> 4)])){
			$this->changedBlocks[$index] = [];
		}
		$this->changedBlocks[$index][Level::blockHash($x, $y, $z)] = $v = new Vector3($x, $y, $z);
		foreach($this->getChunkLoaders($x >> 4, $z >> 4) as $loader){
			$loader->onBlockChanged($v);
		}
	}

	/**
	 * Returns the raw block meta value at the specified coordinates.
	 * @since API 3.0.0
	 *
	 * @param int $x
	 * @param int $y
	 * @param int $z
	 *
	 * @return int 0-15
	 */
	public function getBlockDataAt(int $x, int $y, int $z) : int{
		return $this->getChunk($x >> 4, $z >> 4, true)->getBlockData($x & 0x0f, $y, $z & 0x0f);
	}

	/**
	 * Sets the raw block meta value at the specified coordinates.
	 * @since API 3.0.0
	 *
	 * @param int $x
	 * @param int $y
	 * @param int $z
	 * @param int $data 0-15
	 */
	public function setBlockDataAt(int $x, int $y, int $z, int $data){
		$this->getChunk($x >> 4, $z >> 4, true)->setBlockData($x & 0x0f, $y, $z & 0x0f, $data & 0x0f);
		unset($this->blockCache[Level::blockHash($x, $y, $z)]);
		if(!isset($this->changedBlocks[$index = Level::chunkHash($x >> 4, $z >> 4)])){
			$this->changedBlocks[$index] = [];
		}

		$this->changedBlocks[$index][Level::blockHash($x, $y, $z)] = $v = new Vector3($x, $y, $z);
		foreach($this->getChunkLoaders($x >> 4, $z >> 4) as $loader){
			$loader->onBlockChanged($v);
		}
	}

	/**
	 * Returns the raw block sky light level at the specified coordinates.
	 * @since API 3.0.0
	 *
	 * @param int $x
	 * @param int $y
	 * @param int $z
	 *
	 * @return int 0-15
	 */
	public function getBlockSkyLightAt(int $x, int $y, int $z) : int{
		return $this->getChunk($x >> 4, $z >> 4, true)->getBlockSkyLight($x & 0x0f, $y, $z & 0x0f);
	}

	/**
	 * Sets the raw block sky light level at the specified coordinates.
	 * @since API 3.0.0
	 *
	 * @param int $x
	 * @param int $y
	 * @param int $z
	 * @param int $level 0-15
	 */
	public function setBlockSkyLightAt(int $x, int $y, int $z, int $level){
		$this->getChunk($x >> 4, $z >> 4, true)->setBlockSkyLight($x & 0x0f, $y, $z & 0x0f, $level & 0x0f);
	}

	/**
	 * Returns the raw block light level at the specified coordinates.
	 * @since API 3.0.0
	 *
	 * @param int $x
	 * @param int $y
	 * @param int $z
	 *
	 * @return int 0-15
	 */
	public function getBlockLightAt(int $x, int $y, int $z) : int{
		return $this->getChunk($x >> 4, $z >> 4, true)->getBlockLight($x & 0x0f, $y, $z & 0x0f);
	}

	/**
	 * Sets the raw block light level at the specified coordinates.
	 * @since API 3.0.0
	 *
	 * @param int $x
	 * @param int $y
	 * @param int $z
	 * @param int $level 0-15
	 */
	public function setBlockLightAt(int $x, int $y, int $z, int $level){
		$this->getChunk($x >> 4, $z >> 4, true)->setBlockLight($x & 0x0f, $y, $z & 0x0f, $level & 0x0f);
	}

	/**
	 * Returns the biome ID of the X/Z column at the specified coordinates.
	 * @since API 3.0.0
	 *
	 * @param int $x
	 * @param int $z
	 *
	 * @return int
	 */
	public function getBiomeId(int $x, int $z) : int{
		return $this->getChunk($x >> 4, $z >> 4, true)->getBiomeId($x & 0x0f, $z & 0x0f);
	}

	/**
	 * Sets the biome ID of the X/Z column at the specified coordinates.
	 * @since API 3.0.0
	 *
	 * @param int $x
	 * @param int $z
	 * @param int $biomeId
	 */
	public function setBiomeId(int $x, int $z, int $biomeId){
		$this->getChunk($x >> 4, $z >> 4, true)->setBiomeId($x & 0x0f, $z & 0x0f, $biomeId);
	}

	/**
	 * Returns the raw block extra data value at the specified coordinates.
	 * @since API 3.0.0
	 *
	 * @param int $x
	 * @param int $y
	 * @param int $z
	 *
	 * @return int 16-bit
	 */
	public function getBlockExtraDataAt(int $x, int $y, int $z) : int{
		return $this->getChunk($x >> 4, $z >> 4, true)->getBlockExtraData($x & 0x0f, $y, $z & 0x0f);
	}

	/**
	 * Sets the raw block extra data value at the specified coordinates. Changes the block seen inside the block, for example tall grass inside a snow layer.
	 * This only works on a selection of blocks such as snow layers.
	 * @since API 3.0.0
	 *
	 * @param int $x
	 * @param int $y
	 * @param int $z
	 * @param int $id
	 * @param int $data
	 */
	public function setBlockExtraDataAt(int $x, int $y, int $z, int $id, int $data){
		$this->getChunk($x >> 4, $z >> 4, true)->setBlockExtraData($x & 0x0f, $y, $z & 0x0f, ($data << 8) | $id);

		$pk = new LevelEventPacket();
		$pk->evid = LevelEventPacket::EVENT_SET_DATA;
		$pk->x = $x + 0.5;
		$pk->y = $y + 0.5;
		$pk->z = $z + 0.5;
		$pk->data = ($data << 8) | $id;

		$this->addChunkPacket($x >> 4, $z >> 4, $pk);
	}

	/**
	 * @internal
	 *
	 * @param int $x
	 * @param int $z
	 *
	 * @return int
	 */
	public function getHeightMap(int $x, int $z) : int{
		return $this->getChunk($x >> 4, $z >> 4, true)->getHeightMap($x & 0x0f, $z & 0x0f);
	}

	/**
	 * @internal
	 *
	 * @param int $x
	 * @param int $z
	 * @param int $value
	 */
	public function setHeightMap(int $x, int $z, int $value){
		$this->getChunk($x >> 4, $z >> 4, true)->setHeightMap($x & 0x0f, $z & 0x0f, $value);
	}

	/**
	 * Returns the highest block Y value at the specified coordinates
	 * @since API 3.0.0
	 *
	 * @param int $x
	 * @param int $z
	 *
	 * @return int 0-255
	 */
	public function getHighestBlockAt(int $x, int $z) : int{
		return $this->getChunk($x >> 4, $z >> 4, true)->getHighestBlockAt($x & 0x0f, $z & 0x0f);
	}

	/**
	 * Updates blocks around a Vector3 position.
	 * @since API 3.0.0
	 *
	 * @param Vector3 $pos
	 */
	public function updateBlocksAround(Vector3 $pos){
		$pos = $pos->floor();

		$this->level->getServer()->getPluginManager()->callEvent($ev = new BlockUpdateEvent($this->getBlockAt($pos->x, $pos->y - 1, $pos->z)));
		if(!$ev->isCancelled()){
			$ev->getBlock()->onUpdate(self::BLOCK_UPDATE_NORMAL);
		}

		$this->level->getServer()->getPluginManager()->callEvent($ev = new BlockUpdateEvent($this->getBlockAt($pos->x, $pos->y + 1, $pos->z)));
		if(!$ev->isCancelled()){
			$ev->getBlock()->onUpdate(self::BLOCK_UPDATE_NORMAL);
		}

		$this->level->getServer()->getPluginManager()->callEvent($ev = new BlockUpdateEvent($this->getBlockAt($pos->x - 1, $pos->y, $pos->z)));
		if(!$ev->isCancelled()){
			$ev->getBlock()->onUpdate(self::BLOCK_UPDATE_NORMAL);
		}

		$this->level->getServer()->getPluginManager()->callEvent($ev = new BlockUpdateEvent($this->getBlockAt($pos->x + 1, $pos->y, $pos->z)));
		if(!$ev->isCancelled()){
			$ev->getBlock()->onUpdate(self::BLOCK_UPDATE_NORMAL);
		}

		$this->level->getServer()->getPluginManager()->callEvent($ev = new BlockUpdateEvent($this->getBlockAt($pos->x, $pos->y, $pos->z - 1)));
		if(!$ev->isCancelled()){
			$ev->getBlock()->onUpdate(self::BLOCK_UPDATE_NORMAL);
		}

		$this->level->getServer()->getPluginManager()->callEvent($ev = new BlockUpdateEvent($this->getBlockAt($pos->x, $pos->y, $pos->z + 1)));
		if(!$ev->isCancelled()){
			$ev->getBlock()->onUpdate(self::BLOCK_UPDATE_NORMAL);
		}
	}

	/**
	 * Executes ticks on this dimension
	 * @internal
	 *
	 * @param int $currentTick
	 */
	public function doTick(int $currentTick){
		$this->doWeatherTick($currentTick);
		//TODO: More stuff
	}

	/**
	 * Performs weather ticks
	 * @internal
	 *
	 * @param int $currentTick
	 */
	protected function doWeatherTick(int $currentTick){
		//TODO
	}

	/**
	 * Returns the current rain strength in this dimension
	 * @since API 3.0.0
	 *
	 * @return int
	 */
	public function getRainLevel() : int{
		return $this->rainLevel;
	}

	/**
	 * Sets the rain level and sends changes to players.
	 * @since API 3.0.0
	 *
	 * @param int  $level
	 * @param bool $send
	 */
	public function setRainLevel(int $level, bool $send = true){
		$this->rainLevel = $level;
		$this->sendWeather();
	}

	/**
	 * Sends weather changes to the specified targets, or to all players in the dimension if not specified.
	 * @since API 3.0.0
	 *
	 * @param Player ...$targets
	 */
	public function sendWeather(Player ...$targets){
		$rain = new LevelEventPacket();
		if($this->rainLevel > 0){
			$rain->evid = LevelEventPacket::EVENT_START_RAIN;
			$rain->data = $this->rainLevel;
		}else{
			$rain->evid = LevelEventPacket::EVENT_STOP_RAIN;
		}

		$thunder = new LevelEventPacket();
		if($this->thunderLevel > 0){
			$thunder->evid = LevelEventPacket::EVENT_START_THUNDER;
			$thunder->data = $this->thunderLevel;
		}else{
			$thunder->evid = LevelEventPacket::EVENT_STOP_THUNDER;
		}

		$server = $this->level->getServer();
		if(count($targets) === 0){
			$server->broadcastPacket($this->players, $rain);
			$server->broadcastPacket($this->players, $thunder);
		}else{
			$server->broadcastPacket($targets, $rain);
			$server->broadcastPacket($targets, $thunder);
		}
	}

	/**
	 * Queues a DataPacket to be sent to all players using the specified chunk on the next tick.
	 * @internal
	 *
	 * @param int        $chunkX
	 * @param int        $chunkZ
	 * @param DataPacket $packet
	 */
	public function addChunkPacket(int $chunkX, int $chunkZ, $packet){
		if(!isset($this->chunkPackets[$index = Level::chunkHash($chunkX, $chunkZ)])){
			$this->chunkPackets[$index] = [$packet];
		}else{
			$this->chunkPackets[$index][] = $packet;
		}
	}
}