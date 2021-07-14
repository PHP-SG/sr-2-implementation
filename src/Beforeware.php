<?php

namespace Psg\Sr2\Implementation;

use Psg\Sr1\{ServerRequestInterface};
use Psg\Sr2\{BeforewareInterface, LayeredAppInterface};


class Beforeware implements BeforewareInterface{
	public function process(ServerRequestInterface $request, LayeredAppInterface $app){
		echo "Before\n";
	}
}