<?php

declare(strict_types=1);

namespace pocketmine\world {
	final class World {
		public function getFolderName(): string { return 'world'; }
	}
	final class Position {
		public function __construct(private int $x, private int $y, private int $z) {}
		public function getX(): int { return $this->x; }
		public function getY(): int { return $this->y; }
		public function getZ(): int { return $this->z; }
		public function getWorld(): World { return new World(); }
	}
}

namespace pocketmine\block {
	final class Block {
		public function __construct(private int $type, private \pocketmine\world\Position $position) {}
		public function getTypeId(): int { return $this->type; }
		public function getPosition(): \pocketmine\world\Position { return $this->position; }
	}
}

namespace pocketmine\nbt {
	final class CompoundTag {
		/** @param array<string, mixed> $data */
		public function __construct(public array $data) {}
	}
	final class TreeRoot {
		public function __construct(private CompoundTag $tag) {}
		public function mustGetCompoundTag(): CompoundTag { return $this->tag; }
		public function getTag(): CompoundTag { return $this->tag; }
	}
	final class LittleEndianNbtSerializer {
		public function write(TreeRoot $root): string { return json_encode($root->getTag()->data, JSON_THROW_ON_ERROR); }
		public function read(string $value): TreeRoot { return new TreeRoot(new CompoundTag(json_decode($value, true, 512, JSON_THROW_ON_ERROR))); }
	}
}

namespace pocketmine\item {
	class Item {
		public function __construct(protected int $type, protected string $name = '', protected int $count = 1) {}
		/** @param array<string, mixed> $data */
		public static function legacyJsonDeserialize(array $data): self { return new ItemBlock((int) $data['id'], 'legacy', (int) ($data['count'] ?? 1)); }
		public static function nbtDeserialize(\pocketmine\nbt\CompoundTag $tag): self { return new ItemBlock((int) $tag->data['type'], (string) $tag->data['name'], (int) $tag->data['count']); }
		public function nbtSerialize(): \pocketmine\nbt\CompoundTag { return new \pocketmine\nbt\CompoundTag(['type' => $this->type, 'name' => $this->name, 'count' => $this->count]); }
		public function hasNamedTag(): bool { return $this->name !== ''; }
		public function setCount(int $count): self { $this->count = $count; return $this; }
		public function getCount(): int { return $this->count; }
		public function getCustomName(): string { return $this->name; }
	}
	final class ItemBlock extends Item {
		public function getBlock(): \pocketmine\block\Block { return new \pocketmine\block\Block($this->type, new \pocketmine\world\Position(0, 0, 0)); }
	}
}

namespace NhanAZ\BlockData {
	final class BlockData {
		public static self $instance;
		/** @var array<string, mixed> */
		public array $records = [];
		public static function create(object $plugin, bool $autoCleanup): self { return self::$instance = new self(); }
		private function key(\pocketmine\block\Block $block): string {
			$p = $block->getPosition();
			return $p->getX() . ':' . $p->getY() . ':' . $p->getZ();
		}
		public function get(\pocketmine\block\Block $block): mixed { return $this->records[$this->key($block)] ?? null; }
		public function set(\pocketmine\block\Block $block, mixed $value): void { $this->records[$this->key($block)] = $value; }
		public function remove(\pocketmine\block\Block $block): void { unset($this->records[$this->key($block)]); }
	}
}

namespace pocketmine\event {
	interface Listener {}
}

namespace pocketmine\event\block {
	class BlockBreakEvent {
		public bool $cancelled = false;
		/** @var list<\pocketmine\item\Item> */
		public array $drops = [];
		public function __construct(private \pocketmine\block\Block $block) {}
		public function isCancelled(): bool { return $this->cancelled; }
		public function cancel(): void { $this->cancelled = true; }
		public function getBlock(): \pocketmine\block\Block { return $this->block; }
		/** @param list<\pocketmine\item\Item> $drops */
		public function setDrops(array $drops): void { $this->drops = $drops; }
	}
	final class Transaction {
		/** @param list<array{int, int, int, \pocketmine\block\Block}> $blocks */
		public function __construct(private array $blocks) {}
		public function getBlocks(): array { return $this->blocks; }
	}
	class BlockPlaceEvent {
		public bool $cancelled = false;
		public function __construct(private \pocketmine\item\Item $item, private Transaction $transaction) {}
		public function isCancelled(): bool { return $this->cancelled; }
		public function cancel(): void { $this->cancelled = true; }
		public function getItem(): \pocketmine\item\Item { return $this->item; }
		public function getTransaction(): Transaction { return $this->transaction; }
	}
}

namespace pocketmine\plugin {
	class PluginBase {
		public static string $folder;
		/** @var list<string> */
		public static array $errors = [];
		public function getDataFolder(): string { return self::$folder; }
		public function getServer(): object { return new class {
			public function getPluginManager(): object { return new class {
				public function registerEvents(object $listener, object $plugin): void {}
			}; }
		}; }
		public function getLogger(): object { return new class {
			public function error(string $message): void { PluginBase::$errors[] = $message; }
		}; }
	}
}

namespace {
	require __DIR__ . '/../src/NhanAZ/BlockDataRecorder/Main.php';

	use NhanAZ\BlockData\BlockData;
	use NhanAZ\BlockDataRecorder\Main;
	use pocketmine\block\Block;
	use pocketmine\event\block\BlockBreakEvent;
	use pocketmine\event\block\BlockPlaceEvent;
	use pocketmine\event\block\Transaction;
	use pocketmine\item\ItemBlock;
	use pocketmine\plugin\PluginBase;
	use pocketmine\world\Position;

	function check(bool $condition, string $message): void {
		if (!$condition) { throw new RuntimeException($message); }
	}

	$folder = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'blockdatarecorder-' . bin2hex(random_bytes(6)) . DIRECTORY_SEPARATOR;
	$legacyFolder = $folder . 'BlockData' . DIRECTORY_SEPARATOR . 'world';
	if (!mkdir($legacyFolder, 0777, true)) { throw new RuntimeException('Cannot create fixture data'); }
	PluginBase::$folder = $folder;
	$legacyPath = $legacyFolder . DIRECTORY_SEPARATOR . '1 2 3.yml';
	$original = "1 2 3: '{\"id\":5,\"damage\":0,\"count\":2}'\n";
	file_put_contents($legacyPath, $original);
	$plugin = new Main();
	(new ReflectionMethod(Main::class, 'onEnable'))->invoke($plugin);
	$legacyBlock = new Block(5, new Position(1, 2, 3));
	$break = new BlockBreakEvent($legacyBlock);
	$plugin->onBlockBreak($break);
	check(!$break->cancelled && count($break->drops) === 1 && $break->drops[0]->getCount() === 1, 'Legacy item was not restored');
	check(file_get_contents($legacyPath) === $original, 'Legacy YAML bytes changed');
	check(BlockData::$instance->get($legacyBlock) === ['schema' => 1, 'empty' => true], 'Legacy tombstone missing');
	$repeat = new BlockBreakEvent($legacyBlock);
	$plugin->onBlockBreak($repeat);
	check($repeat->drops === [], 'Legacy item was duplicated');

	$newBlock = new Block(2, new Position(4, 5, 6));
	$plugin->onBlockPlace(new BlockPlaceEvent(new ItemBlock(2, 'Custom', 4), new Transaction([[4, 5, 6, $newBlock]])));
	$placed = BlockData::$instance->get($newBlock);
	check(is_array($placed) && isset($placed['item_nbt_b64']), 'New item record missing');
	$break = new BlockBreakEvent($newBlock);
	$plugin->onBlockBreak($break);
	check(!$break->cancelled && $break->drops[0]->getCustomName() === 'Custom' && $break->drops[0]->getCount() === 1, 'New item data was not restored');
	check(BlockData::$instance->get($newBlock) === null, 'New item record was not retired');

	$badBlock = new Block(2, new Position(7, 8, 9));
	BlockData::$instance->set($badBlock, ['schema' => 1, 'item_nbt_b64' => '!']);
	$break = new BlockBreakEvent($badBlock);
	$plugin->onBlockBreak($break);
	check($break->cancelled && BlockData::$instance->get($badBlock) !== null, 'Corrupt item data was discarded');
	$plugin->onBlockPlace(new BlockPlaceEvent(new ItemBlock(5), new Transaction([[1, 2, 3, $legacyBlock]])));
	check(BlockData::$instance->get($legacyBlock) === ['schema' => 1, 'empty' => true], 'Plain placement exposed stale legacy data');
	check(file_get_contents($legacyPath) === $original, 'Legacy backup was deleted');
	unlink($legacyPath);
	rmdir($legacyFolder);
	rmdir($folder . 'BlockData');
	rmdir($folder);
	echo "Legacy fallback, NBT record, tombstone, and corrupt-data checks passed\n";
}
