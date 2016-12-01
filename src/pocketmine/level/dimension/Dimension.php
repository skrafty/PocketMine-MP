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
use pocketmine\level\dimension\vanilla\{
	Overworld,
	Nether,
	TheEnd
};
use pocketmine\level\format\Chunk;
use pocketmine\level\format\generic\GenericChunk;
use pocketmine\level\Level;
use pocketmine\math\Vector3;
use pocketmine\network\protocol\ChangeDimensionPacket;
use pocketmine\network\protocol\DataPacket;
use pocketmine\Player;
use pocketmine\Server;
use pocketmine\tile\Tile;

/**
 * Base Dimension class
 * @since API 3.0.0
 */
abstract class Dimension{

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
	 * @param Level $level
	 * @param int   $saveId
	 * @param int   $networkId
	 *
	 * @throws \InvalidArgumentException
	 */
	protected function __construct(Level $level, int $saveId, int $networkId = ChangeDimensionPacket::DIMENSION_OVERWORLD){
		$this->level = $level;
		$this->saveId = $saveId;
		if(($type = DimensionType::get($networkId)) instanceof DimensionType){
			$this->dimensionType = $type;
		}else{
			throw new \InvalidArgumentException("Unknown dimension network ID $networkId given");
		}
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
			return $chunk->getTile($pos->x & 0x0f, $pos->y & Level::Y_MASK, $pos->z & 0x0f);
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
	public function getChunk(int $x, int $z, bool $generate = false){
		//TODO: alter this to handle asynchronous chunk loading
		if(isset($this->chunks[$index = Level::chunkHash($x, $z)])){
			return $this->chunks[$index];
		}elseif($this->loadChunk($x, $z, $generate)){
			return $this->chunks[$index];
		}

		return null;
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
	 * @param int $level
	 */
	public function setRainLevel(int $level){
		//TODO
	}

	/**
	 * Sends weather changes to the specified targets, or to all players in the dimension if not specified.
	 * @since API 3.0.0
	 *
	 * @param Player $targets,...
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

		if(count($targets) === 0){
			Server::broadcastPacket($this->players, $rain);
			Server::broadcastPacket($this->players, $thunder);
		}else{
			Server::broadcastPacket($targets, $rain);
			Server::broadcastPacket($targets, $thunder);
		}
	}

	/**
	 * Queues a DataPacket(s) to be sent to all players using the specified chunk on the next tick.
	 * @internal
	 *
	 * @param int        $chunkX
	 * @param int        $chunkZ
	 * @param DataPacket $packets,...
	 */
	public function addChunkPacket(int $x, int $z, ...$packets){
		if(!isset($this->chunkPackets[$index = Level::chunkHash($chunkX, $chunkZ)])){
			$this->chunkPackets[$index] = [$packet];
		}else{
			$this->chunkPackets[$index][] = $packet;
		}
	}
}