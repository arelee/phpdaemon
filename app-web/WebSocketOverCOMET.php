<?php
return new WebSocketOverCOMET;
class WebSocketOverCOMET extends AsyncServer
{
 public $IpcTransSessions = array();
 public $wss;
 /* @method init
    @description Constructor.
    @return void
 */
 public function init()
 {
  Daemon::addDefaultSettings(array(
   'mod'.$this->modname.'enable' => 0,
   'mod'.$this->modname.'ipcpath' => '/tmp/WsOverComet-%s.sock',
  ));
  if (Daemon::$settings['mod'.$this->modname.'enable'])
  {
   $this->wss = Daemon::$appResolver->getInstanceByAppName('WebSocketServer');
   Daemon::log(__CLASS__.' up.');
  }
 }
 /* @method onReady
    @description Called when the worker is ready to go.
    @return void
 */
 public function onReady()
 {
  if (Daemon::$settings['mod'.$this->modname.'enable'])
  {   
   $this->ipcId = sprintf('%x',crc32(Daemon::$worker->pid.'-'.microtime(TRUE)));
   $this->bindSockets('unix:'.sprintf(Daemon::$settings['mod'.$this->modname.'ipcpath'],$this->ipcId),0,FALSE);
   $this->enableSocketEvents();
  }
 }
 /* @method connectIPC
    @description Establish connection with the given application instance of WebSocketOverCOMET.
    @param string ID
    @return integer connId
 */
 public function connectIPC($id)
 {
  if (isset($this->IpcTransSessions[$id])) {return $this->IpcTransSessions[$id];}
  $connId = $this->connectTo('unix:'.sprintf(Daemon::$settings['mod'.$this->modname.'ipcpath'],$id));
  if (!$connId) {return FALSE;}
  $this->sessions[$connId] = new WebSocketOverCOMET_IPCTransSession($connId,$this);
  $this->sessions[$connId]->ipcId = $id;
  $this->IpcTransSessions[$id] = $connId;
  return $connId;
 }
 /* @method onAccepted
    @description Called when new connection is accepted.
    @param integer Connection's ID.
    @param string Address of the connected peer.
    @return void
 */
 public function onAccepted($connId,$addr)
 {
  $this->sessions[$connId] = new WebSocketOverCOMET_IPCRecvSession($connId,$this);
 }
 /* @method beginRequest
    @description Creates Request.
    @param object Request.
    @param object Upstream application instance.
    @return object Request.
 */
 public function beginRequest($req,$upstream)
 {
  return new WebSocketOverCOMET_Request($this,$upstream,$req);
 }
}
class WebSocketOverCOMET_IPCRecvSession extends SocketSession
{
 /* @method init
    @description Constructor.
    @return void
 */
 public function init()
 {
 }
 /* @method stdin
    @description Called when new data recieved.
    @param string New data.
    @return void
 */
 public function stdin($buf)
 {
  $this->buf .= $buf;
  $l = strlen($this->buf);
  if ($l < 5) {return;} // not enough data yet.
  extract(unpack('Chlen/Nblen',binarySubstr($this->buf,0,5)));
  if ($l < 5+$hlen+$blen)  {return;} // not enough data yet.
  $header = binarySubstr($this->buf,5,$hlen);
  $body = binarySubstr($this->buf,5+$hlen,$blen);
  $this->buf = binarySubstr($this->buf,5+$hlen+$blen);
  list($reqId,$authKey) = explode('.',$header);
  if (isset($this->appInstance->queue[$reqId]->downstream) && $this->appInstance->queue[$reqId]->authKey == $authKey)
  {
   $this->appInstance->queue[$reqId]->downstream->onFrame($body,WebSocketServer::STRING);
  }
 }
}
class WebSocketOverCOMET_IPCTransSession extends SocketSession
{
 /* @method onFinish
    @description Called when the session finished.
    @return void
 */
 public function onFinish()
 {
  unset($this->appInstance->sessions[$this->connId]);
  unset($this->appInstance->IpcTransSessions[$this->ipcId]);
 }
}
class WebSocketOverCOMET_Request extends Request
{
 public $inited = FALSE;
 public $authKey;
 public $downstream;
 /* @method run
    @description Called when request iterated.
    @return integer Status.
 */
 public function run()
 {
  if (isset($_REQUEST['page']))
  {
   readfile(dirname(__FILE__).'/../1.html');
   return 1;
  }
  if (!isset($_REQUEST['_pull'])) // Push
  {
   $ret = array();
   $e = explode('.',self::getString($_REQUEST['_id']),2);
   if (sizeof($e) != 2) {$ret['error'] = 'Bad cookie.';}
   elseif (!isset($_REQUEST['data'])) {$ret['error'] = 'No data.';}
   elseif (!is_string($_REQUEST['data'])) {$ret['error'] = 'No data.';}
   elseif ($connId = $this->appInstance->connectIPC(basename($e[0])))
   {
    $this->appInstance->sessions[$connId]->write(pack('CN',strlen($e[1]),strlen($_REQUEST['data'])).$e[1]);
    $this->appInstance->sessions[$connId]->write($_REQUEST['data']);
   }
   else {$ret['error'] = 'IPC error.';}
   echo json_encode($ret);
   return 1;
  }
  else // Pull
  {
   if (!$this->inited)
   {
    $this->authKey = sprintf('%x',crc32(microtime()."\x00".$this->attrs->server['REMOTE_ADDR']));
    $this->header('Content-Type: text/html; charset=utf-8');
    $this->inited = TRUE;
    $this->out('<!--'.str_repeat('-',1024).'->');
    $this->out('<script type="text/javascript">var WebSocket = window.parent.WebSocket; WebSocket._id = "'.$this->appInstance->ipcId.'.'.$this->idAppQueue.'.'.$this->authKey.'"; WebSocket.onopen();</script>'."\n");
    

    $appName = self::getString($_REQUEST['_route']);
    if (!isset($this->appInstance->wss->routes[$appName]))
    {
     if (isset(Daemon::$settings['logerrors']) && Daemon::$settings['logerrors']) {Daemon::log(__METHOD__.': undefined route \''.$appName.'\'.');}
     return 1;
    }
    if (!$this->downstream = call_user_func($this->appInstance->wss->routes[$appName],$this)) {return 1;}
   }
   $this->sleep(1);
  }
 }
 /* @method sendFrame
    @description Sends a frame.
    @param string Frame's data.
    @param integer Frame's type. See the constants.
    @return boolean Success.
 */
 public function sendFrame($data,$type = 0x00)
 {
  $this->out('<script type="text/javascript">WebSocket.onmessage('.json_encode($data).");</script>\n");
  return TRUE;
 }
 /* @method onFinish
    @description Called when the request finished.
    @return void
 */
 public function onFinish()
 {
  unset($this->appInstance->clients[$this->idAppQueue]);
 }
}