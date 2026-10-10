<?php

declare(strict_types=1);

namespace NhanAZ\BlockDataRecorder;

use NhanAZ\BlockData\BlockData;
use pocketmine\block\Block;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\Listener;
use pocketmine\item\Item;
use pocketmine\item\ItemBlock;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\TreeRoot;
use pocketmine\plugin\PluginBase;
use RuntimeException;
use Throwable;

final class Main extends PluginBase implements Listener {

	private BlockData $blockData;

	protected function onEnable(): void {
		$this->blockData = BlockData::create($this, autoCleanup: false);
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
	}

	/** @return array{schema: int, item_nbt_b64: string} */
	private function encodeItem(ItemBlock $item): array {
		$nbt = (new LittleEndianNbtSerializer())->write(new TreeRoot($item->nbtSerialize()));
		return ['schema' => 1, 'item_nbt_b64' => base64_encode($nbt)];
	}

	private function decodeItem(mixed $stored): ItemBlock {
		if (is_array($stored) && ($stored['schema'] ?? null) === 1 && is_string($stored['item_nbt_b64'] ?? null)) {
			$raw = base64_decode($stored['item_nbt_b64'], true);
			if ($raw === false) {
				throw new RuntimeException('Invalid encoded item data');
			}
			$item = Item::nbtDeserialize((new LittleEndianNbtSerializer())->read($raw)->mustGetCompoundTag());
		} elseif (is_string($stored)) {
			$legacy = json_decode($stored, true, 512, JSON_THROW_ON_ERROR);
			if (!is_array($legacy)) {
				throw new RuntimeException('Invalid legacy item JSON');
			}
			$item = Item::legacyJsonDeserialize($legacy);
		} else {
			throw new RuntimeException('Unknown stored item format');
		}
		if (!$item instanceof ItemBlock) {
			throw new RuntimeException('Stored item is not a block item');
		}
		return $item;
	}

	private function legacyPath(Block $block): string {
		$position = $block->getPosition();
		$world = $position->getWorld()->getFolderName();
		if ($world === '' || $world === '.' || $world === '..' || str_contains($world, '/') || str_contains($world, '\\')) {
			throw new RuntimeException('Invalid world folder name');
		}
		$key = $position->getX() . ' ' . $position->getY() . ' ' . $position->getZ();
		return $this->getDataFolder() . 'BlockData' . DIRECTORY_SEPARATOR . $world . DIRECTORY_SEPARATOR . $key . '.yml';
	}

	private function legacyItem(Block $block): ?string {
		$path = $this->legacyPath($block);
		if (!is_file($path)) {
			return null;
		}
		$real = realpath($path);
		$folder = realpath($this->getDataFolder());
		if ($real === false || $folder === false) {
			throw new RuntimeException('Legacy record path could not be resolved');
		}
		$prefix = rtrim($folder, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
		$inside = DIRECTORY_SEPARATOR === '\\'
			? str_starts_with(strtolower($real), strtolower($prefix))
			: str_starts_with($real, $prefix);
		if (!$inside) {
			throw new RuntimeException('Legacy record leaves the plugin data folder');
		}
		$data = yaml_parse_file($path);
		$key = pathinfo($path, PATHINFO_FILENAME);
		if (!is_array($data) || !is_string($data[$key] ?? null)) {
			throw new RuntimeException('Legacy block data is invalid');
		}
		return $data[$key];
	}

	/** @priority HIGHEST */
	public function onBlockPlace(BlockPlaceEvent $event): void {
		if ($event->isCancelled()) {
			return;
		}
		$item = $event->getItem();
		if (!$item instanceof ItemBlock) {
			return;
		}
		foreach ($event->getTransaction()->getBlocks() as [$x, $y, $z, $block]) {
			try {
				if ($item->hasNamedTag()) {
					$this->blockData->set($block, $this->encodeItem($item));
				} elseif (is_file($this->legacyPath($block)) || $this->blockData->get($block) !== null) {
					$this->blockData->set($block, ['schema' => 1, 'empty' => true]);
				}
			} catch (Throwable $e) {
				$event->cancel();
				$this->getLogger()->error('Keeping placed item and recorded block data: ' . $e->getMessage());
			}
			break;
		}
	}

	/** @priority HIGHEST */
	public function onBlockBreak(BlockBreakEvent $event): void {
		if ($event->isCancelled()) {
			return;
		}
		$block = $event->getBlock();
		try {
			$stored = $this->blockData->get($block);
			if (is_array($stored) && ($stored['schema'] ?? null) === 1 && ($stored['empty'] ?? null) === true) {
				return;
			}
			$legacy = $stored === null ? $this->legacyItem($block) : null;
			if ($stored === null && $legacy === null) {
				return;
			}
			$item = $this->decodeItem($stored ?? $legacy);
			if ($item->getBlock()->getTypeId() !== $block->getTypeId()) {
				throw new RuntimeException('Stored item type differs from the current block');
			}
			$item->setCount(1);
			$event->setDrops([$item]);
			if ($legacy !== null || is_file($this->legacyPath($block))) {
				$this->blockData->set($block, ['schema' => 1, 'empty' => true]);
			} else {
				$this->blockData->remove($block);
			}
		} catch (Throwable $e) {
			$event->cancel();
			$this->getLogger()->error('Keeping block and recorded item data: ' . $e->getMessage());
		}
	}
}
