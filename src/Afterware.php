<?php

namespace Psg\Sr2\Implementation;


use Psg\Sr1\{ResponseInterface, ServerRequestInterface};
use Psg\Sr2\{AfterwareInterface, LayeredAppInterface};


class Afterware implements AfterwareInterface{
	public function process(ServerRequestInterface $request, ResponseInterface $response, LayeredAppInterface $app){
		echo "After\n";
	}
}
