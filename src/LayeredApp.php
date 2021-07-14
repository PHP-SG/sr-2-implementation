<?php

namespace Psg\Sr2\Implementation;

use Psg\Sr1\Implementation\Factory\Sr1Factory;
use Psg\Sr2\{MiddlewareInterface, MiddlewareNextInterface, MiddlewareAppInterface};
use Psg\Sr2\{ExitResponseInterface, BeforewareInterface, AfterwareInterface, FrontwareInterface, BackwareInterface, LayeredAppInterface};
use Psg\Sr1\{ResponseInterface, ServerRequestInterface};


class LayeredApp extends Sr1Factory implements LayeredAppInterface{
	public $middleware, $frontware, $backware, $beforeware, $afterware;

	const STAGE_BEFORE = 1;
	const STAGE_FRONT = 2;
	const STAGE_MIDDLE = 4;
	const STAGE_CORE = 8;
	const STAGE_BACK = 16;
	const STAGE_AFTER = 32;

	private $stage; #< the current stage of running outer and middleware

	# allow middleware to treat App like a standard next call
	public function __invoke($request){
		return $this->handle($request);
	}

	public function __get($key){
		if($key == 'stage'){
			return $this->stage;
		}
		throw new \Exception('property "'.$key.'" not found');
	}

	/**
	When flow arrives at the core, it is within the deepest middleware wrap.
	->handle($reqeust) has been called without a response parameter, and the
	latest response object exists at the top most layer - in the middleware
	recursion entry.  To allow core to use the modifications to the response
	provided by Frontware, it is necessary to translate that object into
	the inner layer.  This variable is used for that.
	*/
	public $response;
	public $middleware_request; # the request present within inner middleware wrappings
	/** initialize ware containers and set stage */
	public function __construct(){
		$this->beforeware = new \SplObjectStorage();
		$this->frontware = new \SplObjectStorage();
		$this->middleware = new \SplObjectStorage();
		$this->backware = new \SplObjectStorage();
		$this->afterware = new \SplObjectStorage();
		$this->stage = self::STAGE_BEFORE;
	}
	/**
	because of implementaton constraints (can not accepted an extension interface), this accepts a
	\Psg\Sr2\ServerRequestInterface , but it expects a Psg psr 100 ServerRequestInterface
	*/
	public function handle(ServerRequestInterface $request): ResponseInterface {

		#+ handle beforeware and do initialization {
		if($this->stage === self::STAGE_BEFORE){

			#+ do initialization {
			# seed the response for frontware
			$response = $this->response = $this->createResponse(200);
			# reset for reuse from previous starter calls to handle
			$this->middleware_request = null;

			#+ }

			while($ware = $this->beforeware->current()){
				$this->beforeware->next();
				$ware->process($request, $this);
			}
			$this->stage = self::STAGE_FRONT;
			$this->response = $response; # see var doc
		}
		#+ }



		$initializing_middle = false; #< initializing middle occurs only once, after frontware is done
		#+ front middlware {
		if($this->stage === self::STAGE_FRONT){
			while($ware = $this->frontware->current()){
				$this->frontware->next();
				$results = $ware->process($request, $response, $this);
				$results = is_array($results) ? $results : [$results];
				foreach($results as $result){
					/*
					This section would need to be extended to support
					traditional PSR 7 request and response types
					*/
					if($result instanceof ExitResponseInterface){
						$response = $result;
						# signal to exit, move forward to Backware
						$this->stage = self::STAGE_BACK;
						break;
					}elseif($result instanceof ServerRequestInterface){
						$request = $result;
					}elseif($result instanceof ResponseInterface){
						$response = $result;
					}else{
						throw new \Exception('unrecognized return of frontware.  Should return only a response and/or request');
					}
				}
			}
			if(!($response instanceof ExitResponseInterface)){
				# continuing normally, move to next stage
				$this->stage = self::STAGE_MIDDLE;
				$initializing_middle = true;
			}
		}
		#+ }

		#+ run middleware {
		if($this->stage === self::STAGE_MIDDLE){
			/*
			The layers of middleware mean that handle is called multiple times.
			Each time handle is called, this represents a more inner layer of
			middleware, which is using the most up-to-date request object. So,
			we update the request object property of this instance so we can
			forward that request object to the core when it is time.
			*/
			$this->middleware_request = $request;

			$middleware = $this->middleware->current();
			if($middleware){
				$this->middleware->next();
				$response = $middleware->process($request, $this);


				/*
				Middleware expects the $app->handle function to return a response.
				But, handle runs more than just middleware, so, only return a resopnse
				at this point if we are currently in middleware, as indicated by the
				lack of "$initializing_middle"
				This will handle returning a response for all middleware that is between
				the top and the core.  The core flow won't get to here, and the top will
				be excluded by the if
				*/
				if(!$initializing_middle){
					return $response; # back to the top
				}

				/*
				At this point, we have gone in and out of the middleware wraps, and we
				are back at the top.  The coreware has already been run or bypassed.
				So, we go to the stage after the core.
				*/
				$this->stage = self::STAGE_BACK;
			}
			/*
			At this point, we are either just exhausted the inner path to the core,
			and we need to set stage to core, or we are on our way out of the middleware
			wrapper
			*/
			else{
				if($this->stage === self::STAGE_MIDDLE){
					$this->stage = self::STAGE_CORE;
				}
			}

		}
		#+ }


		/*
		Core represents the core application (the control)

		The last middleware will have executed $app->handle at this point.
		The middleware block agove will recognize there is no more middleware
		and set the status to STAGE_CORE.
		Here, the core is run, and the response is returned back to middleware
		that is wrapping it (which will be the innermost middleware).
		The wrap loop top will still be running with "$initializing_middle = true",
		prevent the return of the top.
		*/
		if($this->stage === self::STAGE_CORE){
			$response = ($this->core)($request, $this->response, $this);
			/*
			This is necessary because
			*/
			$this->stage = self::STAGE_BACK;
			return $response;
		}


		/*
		At this point, we have traversed back up through the
		middleware wrapper recursion, but the $request does
		not reflect what the middleware wrappers changed it
		into; instead, it reflects what it was at the top of
		the middleware wrap recursion.
		Luckily, we saved the version of request at the inner
		depths of the wrappers by using middleware_request,
		and we can now update $request with that value

		As as side note, it's possible middleware_request does
		not exist (when Frontware returned an ExitResponse)
		*/
		if ($this->middleware_request){
			$request = $this->middleware_request;
		}


		#+ handle backware {
		if($this->stage === self::STAGE_BACK){
			while($ware = $this->backware->current()){
				$this->backware->next();
				$response = $ware->process($request, $response, $this);
			}
			$this->stage = self::STAGE_AFTER;
		}
		#+ }

		# send out the HTTP response
		$this->respond($response);

		#+ handle afterware {
		if($this->stage === self::STAGE_AFTER){
			while($ware = $this->afterware->current()){
				$this->afterware->next();
				$ware->process($request, $response, $this);
			}
		}
		#+ }





		return $response;
	}

	public function core($core){
		$this->core = $core;
	}

	public function before($ware){
		$this->beforeware->attach($ware);
	}
	public function after($ware){
		$this->afterware->attach($ware);
	}
	public function front($ware){
		$this->frontware->attach($ware);
	}
	public function back($ware){
		$this->backware->attach($ware);
	}


	public function add($ware){
		if($ware instanceof BeforewareInterface){
			$this->beforeware->attach($ware);
		}elseif($ware instanceof FrontwareInterface){
			$this->frontware->attach($ware);
		}elseif($ware instanceof MiddlewareInterface || $ware instanceof MiddleNextInterface || $ware instanceof \Psr\Http\Server\Middleware || $ware instanceof \Closure ){
			$this->middleware->attach($ware);
		}elseif($ware instanceof BackwareInterface){
			$this->backware->attach($ware);
		}elseif($ware instanceof AfterwareInterface){
			$this->afterware->attach($ware);
		}else{
			throw new \Exception('Could not add unrecognized ware');
		}
	}
	public function remove($middleware){
		if($ware instanceof BeforewareInterface){
			$this->beforeware->detach($ware);
		}elseif($ware instanceof FrontwareInterface){
			$this->frontware->detach($ware);
		}elseif($ware instanceof MiddlewareInterface || $ware instanceof MiddleNextInterface || $ware instanceof \Psr\Http\Server\Middleware || $ware instanceof \Closure ){
			$this->middleware->detach($ware);
		}elseif($ware instanceof BackwareInterface){
			$this->backware->detach($ware);
		}elseif($ware instanceof AfterwareInterface){
			$this->afterware->detach($ware);
		}elseif($ware instanceof \Closure){
			/*
			Since this is a closure and we can't be sure
			where it is attached, detach it from everywhere
			*/
			$this->beforeware->detach($ware);
			$this->frontware->detach($ware);
			$this->middleware->detach($ware);
			$this->backware->detach($ware);
			$this->afterware->detach($ware);
		}else{
			throw new \Exception('Could not remove unrecognized ware');
		}
	}
	/**
	 * Compared to MiddlewareAppInterface, this must be rewritten to
	 * accept all wares
	 */
	public function hasWare($middleware){
		if($ware instanceof BeforewareInterface){
			$this->beforeware->contains($ware);
		}elseif($ware instanceof FrontwareInterface){
			$this->frontware->contains($ware);
		}elseif($ware instanceof MiddlewareInterface || $ware instanceof MiddleNextInterface || $ware instanceof \Psr\Http\Server\Middleware || $ware instanceof \Closure ){
			$this->middleware->contains($ware);
		}elseif($ware instanceof BackwareInterface){
			$this->backware->contains($ware);
		}elseif($ware instanceof AfterwareInterface){
			$this->afterware->contains($ware);
		}elseif($ware instanceof \Closure){
			/*
			Since this is a closure and we can't be sure
			where it is attached, check everywhere
			*/
			return $this->beforeware->contains($ware) ||
				$this->frontware->contains($ware) ||
				$this->middleware->contains($ware) ||
				$this->backware->contains($ware) ||
				$this->afterware->contains($ware);
		}else{
			throw new \Exception('Could not remove unrecognized ware');
		}
	}

	public function respond($response){
		echo "\n==========RESPONSE {=============\n";
		var_export($response->getHeaders());
		echo((string)$response->getBody());
		echo "\n==========} RESPONSE=============\n";
	}

	public function createExitResponse(int $code = 200, string $reasonPhrase = ''): ExitResponseInterface {
		return new ExitResponse($code, [], null, '1.1', $reasonPhrase);
	}
}