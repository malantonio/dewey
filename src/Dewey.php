<?php

class Dewey {

    const DDS_FULL_REGEX = "/([a-z]+)?\s?(\d{1,3})\.?([^\s]*)?\s*([^\s]*)?\s*(.*)?/i";

    /**
     *  calculates range based on *-substituted strings, return as a tuple array
     *
     *      ex. "74*" will return ["740", "750"]
     *      ex. "7**" will return ["700", "800"]
     *
     *  when using a DDS minor will calculate w/ the decimal, but probably not necessary
     * 
     *      ex. "74*.22" will return ["740.22", "750.22"]
     *
     *  @param  string  call number to range, using * to denote where the range is (see above)
     *  @return array   tuple array -> array($minNumber, $maxNumber)
     */

    public static function calculateRange($rangeString) {
        $min = "";
        $max = "";

        $decimalLocation = stripos($rangeString, ".");
        $length = strlen($rangeString);

        for ( $i = 0; $i < $length; $i++ ) {
            $curr = $rangeString[$i];

            $prevPos = $i - 1;
            if ( $prevPos === $decimalLocation ) $prevPos--;

            $prev = $prevPos > -1 ? $rangeString[$prevPos] : "";
            $prevInt = intval($prev);

            if ( $i === $decimalLocation ) {
                $min .= ".";
                $max .= ".";
                continue;
            }

            // any numeric, space, or letter character gets added automatically
            if ( preg_match("/[0-9a-z\s]/i", $curr) ) {
                $min .= $curr;
                $max .= $curr;
            }

            elseif ( $curr === "*" ) {
                // if we're at the first character, we need to stuff these values
                if ( $i === 0 ) {
                    $min .= "0";
                    $max .= "1";
                    continue;
                }

                // if we've handled this already, we just append zeros
                if ( $prev === "*" ) {
                    $min .= "0";
                    $max .= "0";
                    continue;
                }

                // 78* becomes [780, 790]
                if ( $prev !== "9" ) {
                    // advance the previous number of the max
                    $max[$prevPos] = ++$prevInt;

                    // + add zeros
                    $min .= "0";
                    $max .= "0";
                    continue;
                }

                // headache inducing 9 case
                else {
                    $eos = false;
                    while (++$prevInt === 10) {
                        $max[$prevPos] = "0";

                        $prevPos--;

                        if ( $prevPos < 0 ) {
                            $max[0] = "0";
                            $max = "1" . $max;
                            $eos = true;
                            break;
                        }

                        if ( !is_numeric($rangeString[$prevPos]) && $prevPos > 0 ) $prevPos--;

                        $prev = $rangeString[$prevPos];
                        $prevInt = intval($prev);
                    }

                    // if we haven't reached the end of the rangeString,
                    // we'll need to change the number where we left off
                    if (!$eos) $max[$prevPos] = $prevInt;

                    $min .= "0";
                    $max .= "0";
                }
            }
        }

        return array($min, $max);
    }

    /**
     *  compares two DDS call numbers (including cutters) using the provided operator
     *
     *  @param  mixed   base DDS call number, can be string or Dewey\CallNumber object
     *  @param  mixed   DDS call number being compared against base, can be string or Dewey\CallNumber object
     *  @param  string  operator to perform comparison (follows form: $input $operator $comp)
     *  @return bool
     *  @throws InvalidArgumentException
     */

    public static function compare($input, $comp, $operator, $includePrestamp = false) {
        if ( !is_a($input, "Dewey\CallNumber") ) {
            $input = self::parseCallNumber($input);
        }

        if ( !is_a($comp, "Dewey\CallNumber") ) {
            $comp = self::parseCallNumber($comp);
        }

        if ( !!$includePrestamp ) {
            $inputPS = $input->getPrestamp();
            $compPS = $comp->getPrestamp();

            if ( $inputPS !== $compPS ) {
                switch($operator) {
                    case ">":
                    case ">=": return $inputPS > $compPS;

                    case "<":
                    case "<=": return $inputPS < $compPS;

                    case "==":
                    case "===": return false;

   
                    default: throw new \InvalidArgumentException("Invalid operator: [{$operator}]");
                }
            }

       }

        /**
         *  compare the float vals of each call numbers _first_
         *  then, if need be, move on to the normalized cutters
         */

        $inputCNfloat = floatval($input->getCallNumber());
        $compCNfloat = floatval($comp->getCallNumber());

        if ( $inputCNfloat !== $compCNfloat ) {
            switch($operator) {
                case ">" :
                case ">=": return $inputCNfloat > $compCNfloat;

                case "<" :
                case "<=": return $inputCNfloat < $compCNfloat;

                case "==" :
                case "===": return false;

                default: throw new \InvalidArgumentException("Invalid operator: [{$operator}]");
            }
        }

        /**
         *  If our call number is equal, we need to compare cutters.
         *  We'll pad the shorter cutter with 0s.
         */

        // check for longest field to pad
        $inputCTLength = $input->getCutterLength();
        $compCTLength = $comp->getCutterLength();
        $padding = $inputCTLength > $compCTLength ? $inputCTLength : $compCTLength;

        $inputCT = str_pad($input->getCutter(), $padding, "0", STR_PAD_RIGHT);
        $compCT = str_pad($comp->getCutter(), $padding, "0", STR_PAD_RIGHT);

        switch($operator) {
            case ">":  return strtolower($inputCT) >  strtolower($compCT);
            case "<":  return strtolower($inputCT) <  strtolower($compCT);
            case "<=": return strtolower($inputCT) <= strtolower($compCT);
            case ">=": return strtolower($inputCT) >= strtolower($compCT);
            case "==": return strtolower($inputCT) == strtolower($compCT);

            // unneccessary but available
            case "===": return strtolower($inputCT) === strtolower($compCT);
            default: throw new \InvalidArgumentException("Invalid operator: [{$operator}]");
        }
    }

    /**
     *  static wrapper for checking if a call number is within a range
     *
     *  @param  mixed       base DDS call number, can be string or Dewey\CallNumber object
     *  @param  mixed       range to check against
     *  @param  boolean     whether to include $max in equation  (true for LTEQ, false for LT)
     */

    public static function inRange($input, $range, $lessThanEqualTo = true) {
        if ( !is_a($input, "Dewey\CallNumber") ) {
            $input = self::parseCallNumber($input);
        }

        return $input->inRange($range, $lessThanEqualTo);
    }

    /**
     *  parses a Dewey\CallNumber object from a DDS call number string
     *
     *  @param  string              DDS call number input
     *  @return Dewey\CallNumber
     *  @throws InvalidArgumentException
     */

    public static function parseCallNumber($ddString) {
        preg_match(self::DDS_FULL_REGEX, $ddString, $matches);

        // handle bad Call Number
        if ( empty($matches) ) { throw new \InvalidArgumentException("Malformed Dewey Decimal call number"); }

        $prestamp = $matches[1];
        $major = $matches[2];
        $minor = $matches[3];
        $cutter = $matches[4];
        $additionalInfo = $matches[5];

        $cn = new Dewey\CallNumber;
        $cn->setPrestamp($prestamp);
        $cn->setCallNumber($major . "." . $minor);
        $cn->setCutter($cutter);
        $cn->setAdditional($additionalInfo);

        return $cn;
    }
}
