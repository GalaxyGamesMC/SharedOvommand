<?php
declare(strict_types=1);

namespace galaxygames\ovommand\fetus;

use galaxygames\ovommand\BaseSubCommand;
use galaxygames\ovommand\constraint\BaseConstraint;
use galaxygames\ovommand\OvommandHook;
use galaxygames\ovommand\parameter\result\BrokenSyntaxResult;
use galaxygames\ovommand\utils\syntax\SyntaxConst;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\lang\Translatable;
use pocketmine\plugin\Plugin;
use pocketmine\utils\TextFormat;
use shared\galaxygames\ovommand\fetus\BaseResult;
use shared\galaxygames\ovommand\fetus\IOvommand;

abstract class Ovommand extends Command implements IOvommand, IParametable{
	use ParametableTrait;

	/** @var BaseConstraint[] */
	protected array $constraints = [];
	/** @var BaseSubCommand[] $subCommands */ //bad design
	protected array $subCommands = [];
	/** @var CommandSender */
	protected CommandSender $currentSender;

	public function __construct(
		string $name, Translatable|string $description = "", ?string $permission = null,
		Translatable|string|null $usageMessage = null, array $aliases = []
	){
		parent::__construct($name, $description, "", $aliases);

		$this->setAliases(array_unique($aliases));
		if ($permission !== null) {
			$this->setPermission($permission);
		}
		$this->setup();
		$this->setUsage($usageMessage ?? "\n- /" . $this->getName() . " " . implode("\n- /" . $this->getName() . " ", $this->generateUsageList()));
	}

	/**
	 * @param string[]      $args
	 * @param string        $preLabel Return a string combined of its parent labels with the current label
	 */
	final public function execute(CommandSender $sender, string $commandLabel, array $args, string $preLabel = "") : void{
		if (!$this->testPermission($sender)) {
			return;
		}
		if (count($args) < 1) {
			$this->onRun($sender, $commandLabel, []);
			return;
		}
		$label = $args[0];
		$preLabel .= $commandLabel . " " . $preLabel;
		$this->setCurrentSender($sender);
		if (isset($this->subCommands[$label])) {
			array_shift($args);
			$execute = $this->subCommands[$label];
			$execute->setCurrentSender($sender);
			if (!$execute->testPermissionSilent($sender)) {
				$msg = $this->getPermissionMessage();
				if ($msg === null) {
					$sender->sendMessage($sender->getServer()->getLanguage()->translateString(TextFormat::RED . "%commands.generic.permission"));
				} elseif (empty($msg)) {
					$sender->sendMessage(str_replace("<permission>", $execute->getPermissions()[0], $msg));
				}
				return;
			}
			$execute->execute($sender, $label, $args, $preLabel . $label);
		} else {
			$passArgs = $this->parseParameters($args);
			if ($this->onSyntaxError($sender, $commandLabel, $passArgs, $args, $preLabel)) {
				$this->onRun($sender, $commandLabel, $passArgs);
			}
		}
	}

	/**
	 * @return string[]
	 */
	public function generateUsageList() : array{
		$usages = [];
		foreach ($this->subCommands as $k => $subCommand) {
			if ($k === $subCommand->getName()) {
				array_push($usages, ...array_map(static fn(string $in) => $k . " " . $in, $subCommand->generateUsageList()));
			}
		}

		foreach ($this->overloads as $parameters) {
			$param = "";
			foreach ($parameters as $parameter) {
				$hasOptional = $parameter->isOptional();
				$p = $parameter->getName() . ": " . $parameter->getValueName();
				$param .= $hasOptional ? "[$p] " : "<$p> ";
			}
			$usages[] = $param;
		}
		return $usages;
	}

	abstract protected function setup() : void;

	/**
	 * @param BaseResult[]         $args
	 */
	abstract public function onRun(CommandSender $sender, string $label, array $args, string $preLabel = "") : void;

	public function registerSubCommand(BaseSubCommand $subCommand) : void{
		$this->registerSubCommands($subCommand);
	}

	public function registerSubCommands(BaseSubCommand ...$subCommands) : void{
		foreach ($subCommands as $subCommand) {
			if (!isset($this->subCommands[$subName = $subCommand->getName()])) {
				$this->subCommands[$subName] = $subCommand->setParent($this);
				$aliases = [...$subCommand->getShowAliases(), ...$subCommand->getHiddenAliases()];
				foreach ($aliases as $alias) {
					if (!isset($this->subCommands[$alias])) {
						$this->subCommands[$alias] = $subCommand;
					} else {
						throw new \InvalidArgumentException("SubCommand with same alias for '$alias' already exists");
					}
				}
			} else {
				throw new \InvalidArgumentException("SubCommand with same name for '$subName' already exists");
			}
		}
	}

	/**
	 * @param BaseResult[] $args
	 * @param string[] $nonParsedArgs
	 */
	public function onSyntaxError(CommandSender $sender, string $commandLabel, array $args, array $nonParsedArgs = [], string $preLabel = "") : bool{
		var_dump($args);
		foreach ($args as $arg) {
			if ($arg instanceof BrokenSyntaxResult) {
				$arg->setPreLabel($preLabel);
				array_shift($nonParsedArgs);
				$parts = SyntaxConst::getSyntaxBetweenBrokenPart("/" . $arg->getPreLabel() . " " . implode(" ", $nonParsedArgs), $arg->getBrokenSyntax());
				$sender->sendMessage(TextFormat::RED . SyntaxConst::parseOvommandSyntaxMessage($parts[0], $arg->getBrokenSyntax(), $parts[1]));
				return false;
			}
		}
		return true;
	}

	public function setCurrentSender(CommandSender $currentSender) : Ovommand{
		$this->currentSender = $currentSender;
		return $this;
	}

	public function addConstraint(BaseConstraint $constraint) : void{
		$this->constraints[] = $constraint;
	}

	/**
	 * @return BaseConstraint[]
	 */
	public function getConstraints() : array{
		return $this->constraints;
	}

	/**
	 * @return BaseSubCommand[]
	 */
	public function getSubCommands() : array{
		return $this->subCommands;
	}

	public function getOwningPlugin() : ?Plugin{
		return OvommandHook::getOwnedPlugin();
	}
}
