<?php

namespace Psg\Sr2\Implementation;


use Psg\Sr1\{ResponseInterface, ServerRequestInterface};
use Psg\Sr2\{MiddlewareInterface, MiddlewareAppInterface};


class Middleware implements MiddlewareInterface {
	static public $i = 1;
	/*
	< options >:
		exit: < whether to cause a bypass >
		id: < id of middleware >
	*/
	public function __construct($options=[]){
		$defaults = ['exit'=>false, 'id' => self::$i++];
		$this->options = array_merge($defaults, $options);
		$this->id = $this->options['id'];
	}
	public function process(ServerRequestInterface $request, MiddlewareAppInterface $app): ResponseInterface {
		echo "Middle\n";
		# allow for exit
		if($this->options['exit']){
			return $app->createResponse(200, 'exited in middleware')->withHeader('exit_at', 'middleware id '.$this->id);
		}
		#+ wrap echos to show where this exists in the flow {
		echo "wrap$this->id{\n";
		# add header to show each middleware adds one
		$response = $app($request->withAddedHeader('middleware', $this->id));
		echo "}wrap$this->id\n";
		#+ }

		# wrap the body
		$response = $response->withBodyString("wrap$this->id{\n".$response->getBodyString()."}wrap$this->id\n");
		return $response->withAddedHeader('middleware', $this->id);
	}
}