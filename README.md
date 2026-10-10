# BlockDataRecorder

BlockDataRecorder is an Axolotl-PM plugin that restores custom block item data when a player breaks a placed block.

## What it does

When a player places a block item with custom data, the plugin records its item NBT at that block position. Breaking the block returns one item with the recorded custom name, lore, enchantments, and other item data. Ordinary block items use the server's normal drops.

The plugin uses [BlockData](https://github.com/NhanAZ-Libraries/BlockData) for persistent records. The release PHAR includes the pinned library, so a separate BlockData installation is not needed.

## Requirements and compatibility

- Axolotl-PM with plugin API 5.0.0 or later in the 5.x API series.
- PHP 8.1 or later with the extensions bundled by Axolotl-PM, including LevelDB and YAML.

The manifest declares API 5.0.0. Static analysis has been checked against Axolotl-PM 5.0.0 and the pinned server source in [the build workflow](.github/workflows/build.yml). See that workflow for the exact tested source revision. Other releases in the 5.x series have not all been tested.

## Installation and usage

Download the PHAR from [GitHub Releases](https://github.com/NhanAZ-Plugins/BlockDataRecorder/releases), place it in the server's `plugins` directory, and restart the server. There are no commands, permissions, or configuration settings. Place a block item with custom data and break the block to recover one item with that data.

For an upgrade from an older release, back up the plugin data directory before starting the server. Records written by the former BlockData library remain in `BlockData/<world>/<x y z>.yml` under that directory. The plugin reads those files when no newer record exists and leaves their bytes intact. It stores new records in BlockData's LevelDB directory and writes a marker after an old record is consumed to prevent the same item being returned twice. Keep the YAML backup until the migration has been checked on your own worlds.

If a stored item cannot be decoded or does not match the block type, the plugin cancels that break and logs an error so the record can be investigated. It does not discard the stored data.

## Build and development

The [build workflow](.github/workflows/build.yml) checks the migration behavior, runs PHPStan at maximum level, and uses [DevTools](https://github.com/NhanAZ/DevTools) to generate and validate a standalone PHAR. The [BlockData submodule](virions/BlockData) is pinned to a reviewed commit. Clone with submodules to reproduce the build locally.

## License and support

BlockDataRecorder is distributed under [GPL-3.0](LICENSE). The bundled BlockData library retains its [LGPL-3.0-or-later license](virions/BlockData/LICENSE). For help or bug reports, open a [GitHub issue](https://github.com/NhanAZ-Plugins/BlockDataRecorder/issues) or join [NhanAZ Discord](https://discord.gg/j2X83ujT6c).
