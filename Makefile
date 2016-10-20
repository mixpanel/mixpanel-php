
export PATH := "vendor/bin:.:$(PATH)"

all: lib/autoload.php

clean:
	rm -f lib/autoload.php

lib/autoload.php: lib
	phpab --once --static --output lib/autoload.php composer.json
	echo 'require_once __DIR__ . "/compat.php";' >> lib/autoload.php

test:
	phpunit

coverage:
	phpunit --coverage-text

.PHONY: all test
