<?php

declare(strict_types=1);

$server = $argv[1] ?? null;
if ($server === null || !is_file($server . '/vendor/autoload.php')) {
	throw new RuntimeException('Pass an Axolotl-PM source checkout with installed Composer dependencies');
}
require $server . '/vendor/autoload.php';

use pocketmine\block\VanillaBlocks;
use pocketmine\item\Item;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\TreeRoot;

$item = VanillaBlocks::OAK_PLANKS()->asItem();
$item->setCustomName('Migrated block');
$item->setLore(['Recorded line']);
$serializer = new LittleEndianNbtSerializer();
$encoded = $serializer->write(new TreeRoot($item->nbtSerialize()));
$restored = Item::nbtDeserialize($serializer->read($encoded)->mustGetCompoundTag());
if ($restored->getCustomName() !== $item->getCustomName() || $restored->getLore() !== $item->getLore() || $restored->getBlock()->getTypeId() !== $item->getBlock()->getTypeId()) {
	throw new RuntimeException('New item NBT did not round trip');
}

// The former libBedrock ItemFactory mapped oak planks to legacy ID 5.
$legacy = Item::legacyJsonDeserialize([
	'id' => 5,
	'damage' => 0,
	'count' => 2,
	'nbt_b64' => base64_encode($serializer->write(new TreeRoot($item->getNamedTag()))),
]);
if ($legacy->getCustomName() !== $item->getCustomName() || $legacy->getLore() !== $item->getLore() || $legacy->getBlock()->getTypeId() !== $item->getBlock()->getTypeId() || $legacy->getCount() !== 2) {
	throw new RuntimeException('Legacy item JSON did not retain the block item');
}

echo "NBT and legacy JSON item round trips passed\n";
