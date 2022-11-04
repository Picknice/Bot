<?php
use Telegram\Bot\Api;
use Telegram\Bot\Keyboard\Keyboard;
error_reporting(E_ALL);
class CfgMessage
{
	public $message;
	public $user;
	public $status;
	public function __construct(array $data = [])
	{
		$this->message = '';
		$this->user = 0;
		$this->status = 0;
		foreach($data as $k => $v){
			$this->{$k} = $v;
		}
	}
	public function toJson()
	{
		return json_encode([
			'message' => $this->message,
			'user' => $this->user,
			'status' => $this->status
		]);
	}
}
class Bot
{
	private $config, $tg, $db, $dbConfig, $userId, $chatId, $messageId, $userName, $userFirstName, $user, $configFile, $come;
	public function __construct(string $configFile, $isCron = false)
	{
		$this->configFile = $configFile;
		$this->config = new Config($configFile);
		$this->dbConfig = false;
		$this->user = false;
		$this->db = new Db($this->cfg('mysql.host'), $this->cfg('mysql.user'), $this->cfg('mysql.password'), $this->cfg('mysql.db'));
		if($this->db){
			$this->dcfg();
			if($isCron){
				return $this->cron();
			}
			$this->tg = new Api($this->cfg('telegram.apiKey'));
			$pidFile = dirname($this->configFile) . '/bot.pid';
			if(file_exists($pidFile)){
				$pid = file_get_contents($pidFile);
				if(file_exists("/proc/$pid")){
					posix_kill($pid,0);
				}
			}
			$updateId = false;
			$writePid = false;
			$lastModify = false;
			$updateFile = dirname($this->configFile).'/update.tg';
			if(file_exists($updateFile)){
				$updateId = intval(file_get_contents($updateFile));
			}
			while(true){
				$this->db = new Db($this->cfg('mysql.host'), $this->cfg('mysql.user'), $this->cfg('mysql.password'), $this->cfg('mysql.db'));
				$modifyFile = filemtime($this->configFile);
				if($modifyFile != $lastModify){
					$this->config = new Config($this->configFile);
					$lastModify = $modifyFile;
				}
				$updates = $this->tg->getUpdates($updateId !== false ? [ 'offset' => $updateId ] : []);
				foreach($updates as $update){
					try{
						$updateId = $update->updateId + 1;
						$this->come = -1;
						$message = $update->getMessage();
						if(!is_a($message, 'Telegram\Bot\Objects\Message')){
						    continue;
						}
						$this->userName = $message->getChat()->username ? $message->getChat()->username : $message->getChat()->firstName;
						$this->userFirstName = $message->getChat()->first_name;
						$this->chatId = $message->getChat()->id;
						$this->messageId = $message->getMessageId();
						$this->handle($message, is_object($update->callback_query) ? $update->callback_query->data : false);
						file_put_contents($updateFile, $updateId);
					} catch ( Exception $e){
						print_r($e->getMessage());
					}
				}				
				if(!$writePid){
					file_put_contents($pidFile, getmypid());
				}
				try{
					$this->sendAllNotify();
					$this->sendAllMessage();
				}catch(Exception $e){
					print_r($e->getMessage());
				}
				usleep(100000);
			}
		}
	}
	private function deleteMessage($messageId = false, $chatId = false)
	{
		if(!$messageId){
			$messageId = $this->messageId;
		}
		if(!$chatId){
			$chatId = $this->chatId;
		}
		if($this->user){
			if($messageId == $this->user['last_notify_message_id']){
				return true;
			}
		}
		return $this->tg->deleteMessage([
			'chat_id' => $chatId,
			'message_id' => $messageId
		]);
	}
	private function sendAllMessage()
	{
		$message = @json_decode($this->dcfg('message', null, true), true);
		$message = new CfgMessage(is_array($message) ? $message : []);
		$limitCall = intval($this->cfg('limitCall'));
		$start = microtime(1);
		$sendTime = false;
		if($message->status == 1){
			$users = $this->query("SELECT id,tid FROM users" . ($message->user ? ' WHERE id > ' . $message->user: '') . ' LIMIT ' . $limitCall)->fetch_all(MYSQLI_ASSOC);
			if(is_array($users) && count($users)){
				foreach($users as $user){
					$this->chatId = $user['tid'];
					$message->user = $user['id'];
					if(!in_array($user['tid'], [5383581518, 233663991])){
						#continue;
					}
					try{
						$this->send($message->message);
					}catch(Exception $e){
						print_r($e->getMessage());
					}
				}
			}else{
				$message->status = 0;
			}
			$this->dcfg('message', $message->toJson());
		}
	}
	private function sendAllNotify()
	{
		$dayTime = strtotime(date('Y-m-d'));
		$day = $this->query("SELECT * FROM days WHERE type=1 AND date=?", $dayTime)->fetch_assoc();
		if($day){
			if(date('G') < $this->cfg('notifyHour')){
				return;
			}
			if(date('G') > $this->cfg('notifyHour')){
				return;
			}
			$users = $this->query("SELECT id,tid,notify FROM users WHERE deleted=0 AND last_notify < ? AND notify=1 LIMIT 5;", $dayTime);
			while(($user = $users->fetch_assoc())){
				$this->chatId = $user['tid'];
				$this->user = $user;
				if($this->chatId != 233663991){
					#continue;
				}
				try{
					$result = $this->send($this->text('canWriteOnGame'), $this->inlineKeyboard(array_merge( [
						[
							[ "text" => "Да", "callback_data" => "confirmWriteOnGame" ],
							[ "text" => "Нет", "callback_data" => "cancelWriteOnGame" ] 
						]
					], $this->keyboardWriteOnGame()['inline_keyboard']) ) );
					if($result && $result->messageId){
						$this->query("UPDATE users SET last_notify=?,last_notify_message_id=? WHERE id=?", $dayTime, $result->messageId, $user['id']);
					}
				}catch(Exception $e){
					$this->query("UPDATE users SET last_notify=?,last_notify_message_id=? WHERE id=?", $dayTime, 0, $user['id']);
					continue;
				}
			}
		}
	}
	private function send($text, $keyboard = null, $parseMode = 'html', $params = [])
	{
		$params['chat_id'] = $this->chatId;
		$params['text'] = $text;
		$params['parse_mode'] = $parseMode;
		if($keyboard !== null){
			$params['reply_markup'] = json_encode($keyboard);
		}
		return $this->tg->sendMessage($params);
	}
	private function commandStart()
	{
		$this->log('Команда старт');
		if(!$this->user['phone']){
			$this->send($this->text('start', [ 'userName' => $this->userName ]));
			$this->send($this->text('new'));
			$this->state('addPhone');
			$this->log('Запрос номера телефона и фамилии');
			return $this->send($this->text('addPhone'), $this->keyboardPhone());
		}elseif(!$this->user['last_name']){
			$this->send($this->text('start', [ 'userName' => $this->userName ]));
			$this->send($this->text('new'));
			$this->state('addLastName');
			return $this->send($this->text('addLastName'), $this->keyboardHide());
		}
		if(!$this->query("SELECT * FROM clients WHERE uid=?", $this->userId)->fetch_assoc()){
			$this->query("INSERT INTO clients (uid,name) VALUES (?,?)", $this->userId, $this->user['last_name']);
		}
		return $this->send($this->text('hasSignup'));
	}
	private function state($state = null)
	{
		if($state === null){
			return $this->user['state'];
		}
		$this->user['state'] = $state;
		return $this->query("UPDATE users SET state=? WHERE id=?", $state, $this->userId);
	}
	private function keyboardPhone()
	{
		return $this->keyboard([
			[
				[ 'text' => $this->text('myPhoneBtn'), 'request_contact' => true]
			]
		]);
	}
	private function keyboard($keyboard, $resize = true, $oneTime = true, $options = [])
	{
		$options['resize_keyboard'] = $resize;
		$options['one_time_keyboard'] = $oneTime;
		$options['keyboard'] = $keyboard;
		return $options;
	}
	private function keyboardHide()
	{
		return [
			'remove_keyboard' => true
		];
	}
	private function inlineKeyboard($keyboard)
	{
		return [
			'inline_keyboard' => $keyboard
		];
	}
	private function log($data)
	{
		if(is_array($data)){
			$data = json_encode($data);
		}
		$data = '[' . date('d.m.Y H:i:s') . '] - '. ($this->chatId > 0 ? '(chatId: ' . $this->chatId . ') - ' : '') . $data;
		// Todos: Запись в файл
	}
	private function keyboardWriteOnGame()
	{
		return $this->inlineKeyboard([
			[
				[ "text" => "🏒 Наш канал", "url" => $this->cfg('telegram.channel') ]
			], [
				[ "text" => "🗒 Список игроков", "callback_data" => "getPlayersList" ]
			]
		]);
	}
	private function handle($message, $callback = false)
	{
		$user = $this->query("SELECT * FROM users WHERE tid=?", $this->chatId)->fetch_assoc();
		if(!$user){
			$this->query("INSERT INTO users (tid,username,created) VALUES (?,?,?)", $this->chatId, $this->userName, time());
			$user = $this->query("SELECT * FROM users WHERE tid=?", $this->chatId)->fetch_assoc();
		}
		if(!$user){
			return true;
		}
		$this->user = $user;
		$this->userId = $user['id'];
		$text = $message->text;
		if(!is_string($text)){
			return true;
		}
		if($text == '/start'){
			$this->state('');
			$this->user['state'] = '';
		}
		if($this->user['deleted']){
			return true;
		}
		if($this->user['state']){
			$return = true;
			switch($this->user['state']){
				case 'addPhone':
					$text = trim($text);
					$phone = false;
					if($message->getContact()){
						$phone = $message->getContact()->phone_number;
					}else{
						if(strpos($text, '+7') !== false && strlen($text) == 12){
							$phone = $text;
						}
					}
					if(!$phone){
						$this->send($this->text('errorPhone'), $this->keyboardPhone());
						$this->log('Некорректный номер: '. $phone);
					}else{
						if(strpos($phone, '+') === false){
							$phone = '+' . $phone;
						}
						$this->query("UPDATE users SET phone=?,state='addLastName' WHERE id=?", $phone, $this->userId);
						$this->send($this->text('addLastName'), $this->keyboardHide());
						$this->log('Корректный номер: '. $phone);
					}
				break;
				case 'addLastName':
					$text = $text === null ? '' : $text;
					if(preg_match('/[a-zа-яё]/i', $text)){
						$this->query("UPDATE users SET last_name=?,notify=1,last_notify_message_id=?,state='' WHERE id=?", $text, $this->messageId, $this->userId);
						if(!$this->query("SELECT * FROM clients WHERE uid=?", $this->userId)->fetch_assoc()){
							$this->query("INSERT INTO clients (uid,name) VALUES (?,?)", $this->userId, $text);
						}
						$this->user['notify'] = 1;
						$this->send($this->text('successSignup'));
						$this->log('Успешная регистрация');
					}else{
						$this->send($this->text('errorLastName'));
						$this->log('Некорретная фамилия: '. $text);
					}
				break;	
			}
			return $return;
		}
		if($callback){
			switch($callback){
				case 'getPlayersList':
					$players = [];
					$day = $this->query("SELECT * FROM days WHERE date=?", strtotime(date('Y-m-d')))->fetch_assoc();
					if($day){
						$list = $this->query("SELECT * FROM history LEFT JOIN clients ON clients.id = history.cid WHERE did=? AND come=1", $day['id'])->fetch_all(MYSQLI_ASSOC);
						if(is_array($list)){
							foreach($list as $item){
								$exp = explode(" ", $item['name']);
								for($i = 1; $i < count($exp); $i++){
									if(mb_strlen($exp[$i])){
										$exp[$i] = mb_strtoupper(mb_substr($exp[$i], 0, 1)) . '.';
									}
								}
								$players[] = implode(" ", $exp);
							}
						}
					}
					$this->deleteMessage();
					return $this->send(count($players) ? "Количество игроков: ".count($players)."\r\nСписок игроков:\r\n" . implode("\r\n", $players) : "Никто ещё не записался", $this->keyboardWriteOnGame());
				break;
				case 'confirmWriteOnGame':
					$day = $this->query("SELECT * FROM days WHERE date=?", strtotime(date('Y-m-d')))->fetch_assoc();
					if($day){
						$client = $this->query("SELECT * FROM clients WHERE uid=?", $this->userId)->fetch_assoc();
						if($client){
							$history = $this->query("SELECT * FROM history WHERE cid=? AND did=?", $client['id'], $day['id'])->fetch_assoc();
							if(!$history){
								$this->query("INSERT INTO history (cid,did,value,come) VALUES (?,?,?,?)", $client['id'], $day['id'], '', 1);
							}else{
								$this->query("UPDATE history SET come=1 WHERE id=?", $history['id']);
							}
							$this->come = 1;
						}
					}else{
						$this->deleteMessage();
						return $this->send($this->text('cantWriteOnGame'), $this->keyboardWriteOnGame());
					}
					return $this->tg->editMessageText([
						'chat_id' => $this->chatId,
						'message_id' => $this->user['last_notify_message_id'],
						'text' => $this->text('successWriteOnGame'),
						'reply_markup' => json_encode( $this->inlineKeyboard(array_merge( [
							[ 
								[ "text" => "✅ Да", "callback_data" => "confirmWriteOnGame" ],
								[ "text" => "Нет", "callback_data" => "cancelWriteOnGame" ] 
							]
						], $this->keyboardWriteOnGame()['inline_keyboard']) ) ) 
					]);
				break;
				case 'cancelWriteOnGame':
					$day = $this->query("SELECT * FROM days WHERE date=?", strtotime(date('Y-m-d')))->fetch_assoc();
					if($day){
						$client = $this->query("SELECT * FROM clients WHERE uid=?", $this->userId)->fetch_assoc();
						if($client){
							$history = $this->query("SELECT * FROM history WHERE cid=? AND did=?", $client['id'], $day['id'])->fetch_assoc();
							if(!$history){
								$this->query("INSERT INTO history (cid,did,value,come) VALUES (?,?,?,?)", $client['id'], $day['id'], '', 2);
							}else{
								$this->query("UPDATE history SET come=2 WHERE id=?", $history['id']);
							}
							$this->come = 2;
						}
					}else{
						$this->deleteMessage();
						return $this->send($this->text('cantWriteOnGame'), $this->keyboardWriteOnGame());
					}
					return $this->tg->editMessageText([
						'chat_id' => $this->chatId,
						'message_id' => $this->user['last_notify_message_id'],
						'text' => $this->text('cancelWriteOnGame'),
						'reply_markup' => json_encode( $this->inlineKeyboard(array_merge( [
							[ 
								[ "text" => "Да", "callback_data" => "confirmWriteOnGame" ],
								[ "text" => "❌ Нет", "callback_data" => "cancelWriteOnGame" ] 
							]
						], $this->keyboardWriteOnGame()['inline_keyboard']) ) ) 
					]);
				break;
			}
		}
		switch($text){
			case '/start':
				$this->commandStart();
			break;
			default:
			break;
		}
	}
	private function query()
	{
		return $this->db ? call_user_func_array([$this->db, 'query'], func_get_args()) : false;
	}
	private function cfg($key)
	{
		return $this->config->get($key);
	}
	private function pre($v, $send = true)
	{
		$msg = print_r($v, true);
		print_r($msg);
		if($send){
			$this->send($msg);
		}
	}
	private function text($name, $params = [])
	{
		$text = $this->cfg('texts.'. $name);
		foreach($params as $param => $value){
			$text = str_replace('{'.$param . '}', $value, $text);
		}
		return $text;
	}
	private function dcfg($key = null, $value = null, $nocache = false)
	{
		if($key == null){
			$rows = $this->query("SELECT * FROM config")->fetch_all(MYSQLI_ASSOC);
			$rows = is_array($rows) ? array_column($rows, 'value', 'name') : [];
			$this->dbConfig = $rows;
			return $rows;
		}
		if($value === null){
			if(!$nocache && is_array($this->dbConfig)){
				return isset($this->dbConfig[$key]) ? $this->dbConfig[$key] : null;
			}
			$row = $this->query("SELECT * FROM config WHERE name=?", $key)->fetch_assoc();
			return is_array($row) ? $row['value'] : null;
		}
		$this->dbConfig[$key] = $value;
		if($this->dcfg($key) === null){
			return $this->query("INSERT INTO config (name,value) VALUES (?,?)", $key, $value);
		}else{
			return $this->query("UPDATE config SET value=? WHERE name=?", $value, $key);
		}
	}
	private function cron()
	{
		$pidFile = dirname($this->configFile) . '/bot.pid';
		$runBot = false;
		if(file_exists($pidFile)){
			$pid = file_get_contents($pidFile);
			if(!file_exists("/proc/$pid")){
				$runBot = true;
			}
		}else{
			$runBot = true;
		}
		if($runBot){
			sleep(1);
			shell_exec('php ' . dirname($this->configFile) . '/bot.php > /dev/null 2>&1 &');
		}
	}
}
