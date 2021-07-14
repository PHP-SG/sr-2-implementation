<?php

namespace Psg\Sr2\Implementation;


use Psg\Sr1\{ResponseInterface, ServerRequestInterface};
use Psg\Sr2\{BackwareInterface, LayeredAppInterface};


class Backware implements BackwareInterface {
	public function process(ServerRequestInterface $request, ResponseInterface $response, LayeredAppInterface $app): ResponseInterface {
		echo "Back\n";
		return $response->withHeader('Backware', '1');
	}
}