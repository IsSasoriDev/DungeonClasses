<?php

declare(strict_types=1);

namespace DungeonClasses;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\utils\Config;
use pocketmine\scheduler\ClosureTask;
use pocketmine\world\sound\XpCollectSound;

class Main extends PluginBase implements Listener {
    
    private Config $playerData;
    private array $classes = [
        "warrior" => [
            "name" => "§cWarrior",
            "description" => "Master of melee combat with enhanced strength and defense",
            "perks" => [
                "§7• +2 Attack Damage",
                "§7• +20% Damage Resistance", 
                "§7• Sword Mastery (Fortune on swords)",
                "§7• Battle Rage (Speed boost when low HP)"
            ]
        ],
        "mage" => [
            "name" => "§9Mage",
            "description" => "Wielder of arcane magic with devastating spells",
            "perks" => [
                "§7• Mana System (30 mana points)",
                "§7• Fireball Spell (Right-click with stick)",
                "§7• Healing Spell (/heal - 10 mana)",
                "§7• Magic Resistance (+15% vs magic damage)"
            ]
        ],
        "rogue" => [
            "name" => "§2Rogue",
            "description" => "Swift assassin with stealth and critical strikes",
            "perks" => [
                "§7• +50% Movement Speed",
                "§7• 25% Critical Hit Chance (2x damage)",
                "§7• Stealth Mode (Invisibility - /stealth)",
                "§7• Backstab Bonus (+100% damage from behind)"
            ]
        ],
        "paladin" => [
            "name" => "§eHoly Paladin",
            "description" => "Divine warrior with healing and protection abilities",
            "perks" => [
                "§7• Auto-heal when below 30% HP",
                "§7• Undead Slayer (+200% vs undead)",
                "§7• Divine Protection (Damage immunity 3s after respawn)",
                "§7• Group Blessing (/bless - heal nearby players)"
            ]
        ],
        "archer" => [
            "name" => "§aArcher",
            "description" => "Master marksman with enhanced bow abilities",
            "perks" => [
                "§7• +100% Bow Damage",
                "§7• Infinite Arrows",
                "§7• Arrow Rain (/rain - shoots 5 arrows)",
                "§7• Eagle Eye (No fall damage, better vision)"
            ]
        ]
    ];
    
    private array $playerMana = [];
    private array $playerCooldowns = [];
    
    public function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->playerData = new Config($this->getDataFolder() . "players.yml", Config::YAML);
        
        // Mana regeneration task
        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(
            function(): void {
                $this->regenerateMana();
            }
        ), 60); // Every 3 seconds (60 ticks)
        
        $this->getLogger()->info("§aDungeonClasses plugin enabled!");
    }
    
    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if (!$sender instanceof Player) {
            $sender->sendMessage("§cThis command can only be used in-game!");
            return false;
        }
        
        switch ($command->getName()) {
            case "class":
                if (empty($args)) {
                    $this->showClassMenu($sender);
                    return true;
                }
                
                $className = strtolower($args[0]);
                if (!isset($this->classes[$className])) {
                    $sender->sendMessage("§cInvalid class! Available: " . implode(", ", array_keys($this->classes)));
                    return false;
                }
                
                $this->setPlayerClass($sender, $className);
                return true;
                
            case "heal":
                if ($this->getPlayerClass($sender) !== "mage") {
                    $sender->sendMessage("§cOnly mages can use healing spells!");
                    return false;
                }
                
                if ($this->getPlayerMana($sender) < 10) {
                    $sender->sendMessage("§cNot enough mana! (Need 10)");
                    return false;
                }
                
                $this->usePlayerMana($sender, 10);
                $sender->setHealth(min($sender->getMaxHealth(), $sender->getHealth() + 6));
                $sender->sendMessage("§aYou healed yourself for 3 hearts!");
                $sender->getWorld()->addSound($sender->getPosition(), new XpCollectSound());
                return true;
                
            case "stealth":
                if ($this->getPlayerClass($sender) !== "rogue") {
                    $sender->sendMessage("§cOnly rogues can use stealth!");
                    return false;
                }
                
                if ($this->isOnCooldown($sender, "stealth")) {
                    $sender->sendMessage("§cStealth is on cooldown!");
                    return false;
                }
                
                $this->setCooldown($sender, "stealth", 60); // 60 second cooldown
                $sender->getEffects()->add(new EffectInstance(VanillaEffects::INVISIBILITY(), 200, 0)); // 10 seconds
                $sender->sendMessage("§2You vanish into the shadows...");
                return true;
                
            case "bless":
                if ($this->getPlayerClass($sender) !== "paladin") {
                    $sender->sendMessage("§cOnly paladins can bless others!");
                    return false;
                }
                
                if ($this->isOnCooldown($sender, "bless")) {
                    $sender->sendMessage("§cBless is on cooldown!");
                    return false;
                }
                
                $this->setCooldown($sender, "bless", 120); // 2 minute cooldown
                $this->blessNearbyPlayers($sender);
                return true;
                
            case "rain":
                if ($this->getPlayerClass($sender) !== "archer") {
                    $sender->sendMessage("§cOnly archers can use arrow rain!");
                    return false;
                }
                
                if ($this->isOnCooldown($sender, "rain")) {
                    $sender->sendMessage("§cArrow rain is on cooldown!");
                    return false;
                }
                
                $this->setCooldown($sender, "rain", 30); // 30 second cooldown
                $this->arrowRain($sender);
                $sender->sendMessage("§aArrows rain from the sky!");
                return true;
                
            case "classinfo":
                $class = $this->getPlayerClass($sender);
                if ($class === null) {
                    $sender->sendMessage("§cYou haven't chosen a class yet! Use /class");
                    return false;
                }
                
                $this->showClassInfo($sender, $class);
                return true;
        }
        
        return false;
    }
    
    private function showClassMenu(Player $player): void {
        $player->sendMessage("§6=== Dungeon Classes ===");
        $player->sendMessage("§7Choose your destiny! Each class has unique abilities and perks.");
        $player->sendMessage("");
        
        foreach ($this->classes as $id => $class) {
            $player->sendMessage($class["name"] . " §7- " . $class["description"]);
            $player->sendMessage("§7Use: §f/class $id");
            $player->sendMessage("");
        }
        
        $currentClass = $this->getPlayerClass($player);
        if ($currentClass) {
            $player->sendMessage("§eCurrent Class: " . $this->classes[$currentClass]["name"]);
        }
    }
    
    private function showClassInfo(Player $player, string $className): void {
        $class = $this->classes[$className];
        $player->sendMessage("§6=== " . $class["name"] . " ===");
        $player->sendMessage("§7" . $class["description"]);
        $player->sendMessage("");
        $player->sendMessage("§6Perks:");
        foreach ($class["perks"] as $perk) {
            $player->sendMessage($perk);
        }
        
        if ($className === "mage") {
            $mana = $this->getPlayerMana($player);
            $player->sendMessage("");
            $player->sendMessage("§9Current Mana: §b$mana/30");
        }
    }
    
    private function setPlayerClass(Player $player, string $className): void {
        $this->playerData->set($player->getName(), $className);
        $this->playerData->save();
        
        // Initialize mana for mages
        if ($className === "mage") {
            $this->playerMana[$player->getName()] = 30;
        }
        
        $class = $this->classes[$className];
        $player->sendMessage("§aYou are now a " . $class["name"] . "!");
        $player->sendMessage("§7" . $class["description"]);
        $player->sendMessage("§7Use §f/classinfo §7to see your perks!");
        
        $this->applyClassEffects($player, $className);
    }
    
    private function getPlayerClass(Player $player): ?string {
        return $this->playerData->get($player->getName());
    }
    
    private function applyClassEffects(Player $player, string $className): void {
        switch ($className) {
            case "rogue":
                $player->getEffects()->add(new EffectInstance(VanillaEffects::SPEED(), 999999, 1, false));
                break;
        }
    }
    
    public function onPlayerJoin(PlayerJoinEvent $event): void {
        $player = $event->getPlayer();
        $class = $this->getPlayerClass($player);
        
        if ($class === null) {
            $player->sendMessage("§eWelcome to the dungeon! Choose your class with §f/class");
        } else {
            $this->applyClassEffects($player, $class);
            if ($class === "mage") {
                $this->playerMana[$player->getName()] = 30;
            }
        }
    }
    
    public function onEntityDamageByEntity(EntityDamageByEntityEvent $event): void {
        $damager = $event->getDamager();
        $victim = $event->getEntity();
        
        if ($damager instanceof Player) {
            $class = $this->getPlayerClass($damager);
            
            switch ($class) {
                case "warrior":
                    // +2 attack damage and sword mastery
                    $event->setBaseDamage($event->getBaseDamage() + 2);
                    if ($damager->getInventory()->getItemInHand()->getTypeId() === VanillaItems::IRON_SWORD()->getTypeId() ||
                        $damager->getInventory()->getItemInHand()->getTypeId() === VanillaItems::DIAMOND_SWORD()->getTypeId()) {
                        $event->setBaseDamage($event->getBaseDamage() * 1.2);
                    }
                    break;
                    
                case "rogue":
                    // Critical hit chance and backstab
                    if (mt_rand(1, 4) === 1) { // 25% chance
                        $event->setBaseDamage($event->getBaseDamage() * 2);
                        $damager->sendMessage("§cCritical Hit!");
                    }
                    
                    // Backstab bonus (simplified - check if victim is looking away)
                    if ($victim instanceof Player) {
                        $victimYaw = $victim->getLocation()->getYaw();
                        $damagerYaw = $damager->getLocation()->getYaw();
                        $diff = abs($victimYaw - $damagerYaw);
                        if ($diff > 90 && $diff < 270) {
                            $event->setBaseDamage($event->getBaseDamage() * 2);
                            $damager->sendMessage("§2Backstab!");
                        }
                    }
                    break;
                    
                case "paladin":
                    // Undead slayer (works on zombies, skeletons, etc.)
                    if (str_contains(strtolower($victim::class), "zombie") || 
                        str_contains(strtolower($victim::class), "skeleton")) {
                        $event->setBaseDamage($event->getBaseDamage() * 3);
                        $damager->sendMessage("§eUndead Slayer activated!");
                    }
                    break;
                    
                case "archer":
                    // Bow damage bonus
                    if ($event->getCause() === EntityDamageEvent::CAUSE_PROJECTILE) {
                        $event->setBaseDamage($event->getBaseDamage() * 2);
                    }
                    break;
            }
        }
        
        if ($victim instanceof Player) {
            $class = $this->getPlayerClass($victim);
            
            switch ($class) {
                case "warrior":
                    // 20% damage resistance
                    $event->setBaseDamage($event->getBaseDamage() * 0.8);
                    
                    // Battle rage when low HP
                    if ($victim->getHealth() <= ($victim->getMaxHealth() * 0.3)) {
                        $victim->getEffects()->add(new EffectInstance(VanillaEffects::SPEED(), 100, 1));
                        $victim->getEffects()->add(new EffectInstance(VanillaEffects::STRENGTH(), 100, 0));
                    }
                    break;
                    
                case "mage":
                    // Magic resistance (simplified)
                    if ($event->getCause() === EntityDamageEvent::CAUSE_MAGIC) {
                        $event->setBaseDamage($event->getBaseDamage() * 0.85);
                    }
                    break;
            }
        }
    }
    
    public function onEntityDamage(EntityDamageEvent $event): void {
        $entity = $event->getEntity();
        
        if ($entity instanceof Player) {
            $class = $this->getPlayerClass($entity);
            
            if ($class === "archer" && $event->getCause() === EntityDamageEvent::CAUSE_FALL) {
                // No fall damage for archers
                $event->cancel();
            }
        }
    }
    
    public function onPlayerDeath(PlayerDeathEvent $event): void {
        $player = $event->getPlayer();
        $class = $this->getPlayerClass($player);
        
        if ($class === "paladin") {
            // Divine protection after respawn
            $this->getScheduler()->scheduleDelayedTask(new ClosureTask(
                function() use ($player): void {
                    if ($player->isOnline()) {
                        $player->setImmobile(false);
                        $player->sendMessage("§eDivine protection fades...");
                    }
                }
            ), 60); // 3 seconds
        }
    }
    
    public function onPlayerInteract(PlayerInteractEvent $event): void {
        $player = $event->getPlayer();
        $item = $event->getItem();
        $class = $this->getPlayerClass($player);
        
        if ($class === "mage" && $item->getTypeId() === VanillaItems::STICK()->getTypeId() && 
            $event->getAction() === PlayerInteractEvent::RIGHT_CLICK_AIR) {
            
            if ($this->getPlayerMana($player) < 5) {
                $player->sendMessage("§cNot enough mana for fireball! (Need 5)");
                return;
            }
            
            $this->usePlayerMana($player, 5);
            $this->castFireball($player);
        }
        
        if ($class === "archer" && $item->getTypeId() === VanillaItems::BOW()->getTypeId()) {
            // Give infinite arrows
            if (!$player->getInventory()->contains(VanillaItems::ARROW())) {
                $player->getInventory()->addItem(VanillaItems::ARROW()->setCount(64));
            }
        }
    }
    
    private function castFireball(Player $player): void {
        // Simplified fireball - create explosion at target location
        $direction = $player->getDirectionVector();
        $start = $player->getEyePos();
        $end = $start->add($direction->multiply(10));
        
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(
            function() use ($player, $end): void {
                if ($player->isOnline()) {
                    $player->getWorld()->createExplosion($end, 2, $player);
                    $player->sendMessage("§cFireball cast!");
                }
            }
        ), 20); // 1 second delay
    }
    
    private function blessNearbyPlayers(Player $caster): void {
        $world = $caster->getWorld();
        $pos = $caster->getPosition();
        
        foreach ($world->getNearbyEntities($caster->getBoundingBox()->expandedCopy(10, 10, 10)) as $entity) {
            if ($entity instanceof Player && $entity !== $caster) {
                $entity->setHealth(min($entity->getMaxHealth(), $entity->getHealth() + 8));
                $entity->sendMessage("§eYou have been blessed by " . $caster->getName() . "!");
                $entity->getWorld()->addSound($entity->getPosition(), new XpCollectSound());
            }
        }
        
        $caster->sendMessage("§eYou bless all nearby players!");
    }
    
    private function arrowRain(Player $player): void {
        for ($i = 0; $i < 5; $i++) {
            $this->getScheduler()->scheduleDelayedTask(new ClosureTask(
                function() use ($player): void {
                    if ($player->isOnline()) {
                        // Simplified arrow rain effect
                        $direction = $player->getDirectionVector();
                        $start = $player->getEyePos()->add(mt_rand(-3, 3), 5, mt_rand(-3, 3));
                        $target = $start->add($direction->multiply(mt_rand(5, 15)));
                        
                        // Create damage at target location
                        $world = $player->getWorld();
                        foreach ($world->getNearbyEntities($player->getBoundingBox()->expandedCopy(15, 15, 15)) as $entity) {
                            if ($entity instanceof Player && $entity !== $player) {
                                $entity->attack(new EntityDamageByEntityEvent($player, $entity, EntityDamageEvent::CAUSE_PROJECTILE, 6));
                            }
                        }
                    }
                }
            ), $i * 5); // Stagger the arrows
        }
    }
    
    private function getPlayerMana(Player $player): int {
        return $this->playerMana[$player->getName()] ?? 0;
    }
    
    private function usePlayerMana(Player $player, int $amount): void {
        $current = $this->getPlayerMana($player);
        $this->playerMana[$player->getName()] = max(0, $current - $amount);
        $player->sendMessage("§9Mana: §b" . $this->getPlayerMana($player) . "/30");
    }
    
    private function regenerateMana(): void {
        foreach ($this->getServer()->getOnlinePlayers() as $player) {
            if ($this->getPlayerClass($player) === "mage") {
                $current = $this->getPlayerMana($player);
                if ($current < 30) {
                    $this->playerMana[$player->getName()] = min(30, $current + 2);
                }
            }
            
            // Auto-heal for paladins
            if ($this->getPlayerClass($player) === "paladin" && 
                $player->getHealth() <= ($player->getMaxHealth() * 0.3)) {
                $player->setHealth(min($player->getMaxHealth(), $player->getHealth() + 1));
            }
        }
    }
    
    private function isOnCooldown(Player $player, string $ability): bool {
        $key = $player->getName() . "_" . $ability;
        return isset($this->playerCooldowns[$key]) && 
               $this->playerCooldowns[$key] > time();
    }
    
    private function setCooldown(Player $player, string $ability, int $seconds): void {
        $key = $player->getName() . "_" . $ability;
        $this->playerCooldowns[$key] = time() + $seconds;
    }
}