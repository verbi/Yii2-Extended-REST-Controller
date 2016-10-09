<?php

namespace verbi\yii2ExtendedRestController;
use \Yii;

/**
 * @author Philip Verbist <philip.verbist@gmail.com>
 * @link https://github.com/verbi/yii2-extended-activerecord/
 * @license https://opensource.org/licenses/GPL-3.0
 */
class UrlRule extends \yii\rest\UrlRule {
    /**
     * @var array list of tokens that should be replaced for each pattern. The keys are the token names,
     * and the values are the corresponding replacements.
     * @see patterns
     */
    public $tokens = [
        '{id}' => '<id>',
    ];
    
    /**
     * @inheritdoc
     */
    protected function createRules()
    {
        $only = array_flip( $this->only );
        $except = array_flip( $this->except );
        $patterns = $this->extraPatterns + $this->patterns;
        $rules = [];
        
        foreach ( $this->controller as $urlName => $controller ) {
            if( Yii::$app->hasModule( $controller ) ) {
                $prefix = trim( $this->prefix . '/' . $urlName, '/' );
                foreach ( $patterns as $pattern => $action ) {
                    if ( !isset( $except[$action] ) && ( empty( $only ) || isset( $only[$action] ) ) ) {
                        $rules[$urlName][] = $this->createRule( 
                                $pattern, 
                                $prefix, 
                                $controller 
                                . '/' 
                                . Yii::$app->getModule( $controller )
                                ->defaultRoute 
                                . '/' 
                                . $action 
                                );
                    }
                }
                unset($this->controller[$urlName]);
            }
        }
        return array_merge($rules, parent::createRules());
    }
}