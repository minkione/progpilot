<?php

/*
 * This file is part of ProgPilot, a static analyzer for security
 *
 * @copyright 2017 Eric Therond. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */


namespace progpilot\Dataflow;

use progpilot\Objects\MyInstance;
use progpilot\Objects\MyCode;
use progpilot\Objects\ArrayStatic;
use progpilot\Objects\MyClass;
use progpilot\Objects\MyOp;
use progpilot\Objects\MyDefinition;
use progpilot\Objects\MyFunction;

use progpilot\Dataflow\Definitions;
use progpilot\Code\Opcodes;

class VisitorDataflow
{
    private $defs;
    private $blocks;
    private $current_block_id;

    /* representations */
    private $current_func;

    public function __construct()
    {
    }

    protected function getBlockId($myblock)
    {

        if (isset($this->blocks[$myblock]))
            return $this->blocks[$myblock];

        return -1;
    }

    protected function setBlockId($myblock)
    {

        if (!isset($this->blocks[$myblock]))
            $this->blocks[$myblock] = count($this->blocks);
    }

    public function analyze($context, $myfunc, $defs_included = null)
    {
        $mycode = $myfunc->get_mycode();
        $code = $mycode->get_codes();

        $index = 0;
        $myfunc->get_mycode()->set_end(count($code));

        $blocks_stack_id = [];
        $last_block_id = 0;
        $first_block = true;

        do
        {
            if (isset($code[$index]))
            {
                $instruction = $code[$index];

                switch ($instruction->get_opcode())
                {
                case Opcodes::START_EXPRESSION:
                {
                    // representations start
                    $id_cfg = $this->current_func->getLine()."-".$this->current_func->getColumn()."-".$this->current_block_id;
                    $context->outputs->cfg->add_textofmyblock($id_cfg, Opcodes::START_EXPRESSION."\n");
                    // representations end
                    break;
                }

                case Opcodes::END_EXPRESSION:
                {
                    // representations start
                    $id_cfg = $this->current_func->getLine()."-".$this->current_func->getColumn()."-".$this->current_block_id;
                    $context->outputs->cfg->add_textofmyblock($id_cfg, Opcodes::END_EXPRESSION."\n");
                    // representations end
                    break;
                }

                case Opcodes::CLASSE:
                {
                    $myclass = $instruction->get_property("myclass");
                    foreach ($myclass->get_properties() as $property)
                    {
                        if (is_null($property->get_source_myfile()))
                            $property->set_source_myfile($context->get_current_myfile());
                    }

                    break;
                }

                case Opcodes::ENTER_FUNCTION:
                {
                    $block_id_zero = hash("sha256", "0-".$context->get_current_myfile()->get_name());

                    $myfunc = $instruction->get_property("myfunc");

                    $blocks = new \SplObjectStorage;
                    $defs = new Definitions();
                    $defs->create_block($block_id_zero);

                    $myfunc->set_defs($defs);
                    $myfunc->set_blocks($blocks);

                    $this->defs = $defs;
                    $this->blocks = $blocks;

                    $this->current_block_id = $block_id_zero;
                    $this->current_func = $myfunc;

                    if ($myfunc->is_type(MyFunction::TYPE_FUNC_METHOD))
                    {
                        $thisdef = $myfunc->get_this_def();
                        $thisdef->set_source_myfile($context->get_current_myfile());

                        $id_object = $context->get_objects()->add_object();
                        $thisdef->set_object_id($id_object);
                        $thisdef->set_block_id($block_id_zero);

                        $this->defs->adddef($thisdef->get_name(), $thisdef);
                        $this->defs->addgen($thisdef->get_block_id(), $thisdef);
                    }

                    // representations start
                    $id_cfg = $this->current_func->getLine()."-".$this->current_func->getColumn()."-".$this->current_block_id;
                    $context->outputs->callgraph->add_node($myfunc);
                    $context->outputs->cfg->add_textofmyblock($id_cfg, Opcodes::ENTER_FUNCTION." ".htmlentities($myfunc->get_name(), ENT_QUOTES, 'UTF-8')."\n");
                    // representations end

                    break;
                }

                case Opcodes::ENTER_BLOCK:
                {
                    $myblock = $instruction->get_property("myblock");

                    $this->setBlockId($myblock);
                    $block_id_tmp = $this->getBlockId($myblock);
                    $block_id = hash("sha256", "$block_id_tmp-".$context->get_current_myfile()->get_name());
                    $myblock->set_id($block_id);


                    array_push($blocks_stack_id, $block_id);
                    $this->current_block_id = $block_id;

                    if ($block_id != hash("sha256", "0-".$context->get_current_myfile()->get_name()))
                        $this->defs->create_block($block_id);

                    $assertions = $myblock->get_assertions();
                    foreach ($assertions as $assertion)
                    {
                        $mydef = $assertion->get_def();
                        $mydef->set_block_id($block_id);
                    }

                    // representations start
                    $id_cfg = $this->current_func->getLine()."-".$this->current_func->getColumn()."-".$this->current_block_id;
                    $context->outputs->cfg->add_textofmyblock($id_cfg, Opcodes::ENTER_BLOCK."\n");
                    $context->outputs->cfg->add_node($id_cfg, $myblock);

                    foreach ($myblock->parents as $parent)
                        $context->outputs->cfg->add_edge($parent, $myblock);
                    // representations end

                    if ($first_block && !is_null($defs_included) && $this->current_func->get_name() == "{main}")
                    {
                        foreach ($defs_included as $def_included)
                        {
                            $this->defs->adddef($def_included->get_name(), $def_included);
                            $this->defs->addgen($block_id, $def_included);
                        }

                        $first_block = false;
                    }

                    break;
                }

                case Opcodes::LEAVE_BLOCK:
                {
                    $myblock = $instruction->get_property("myblock");

                    $blockid = $myblock->get_id();

                    $pop = array_pop($blocks_stack_id);

                    if (count($blocks_stack_id) > 0)
                        $this->current_block_id = $blocks_stack_id[count($blocks_stack_id) - 1];

                    $this->defs->computekill($context, $blockid);

                    $last_block_id = $blockid;

                    // representations start
                    $id_cfg = $this->current_func->getLine()."-".$this->current_func->getColumn()."-".$this->current_block_id;
                    $context->outputs->cfg->add_textofmyblock($id_cfg, Opcodes::LEAVE_BLOCK."\n");
                    // representations end

                    break;
                }


                case Opcodes::LEAVE_FUNCTION:
                {
                    $this->defs->reachingDefs($this->blocks);

                    $myfunc = $instruction->get_property("myfunc");
                    $myfunc->set_last_block_id($last_block_id);

                    // representations start
                    $id_cfg = $this->current_func->getLine()."-".$this->current_func->getColumn()."-".$this->current_block_id;
                    $context->outputs->cfg->add_textofmyblock($id_cfg, Opcodes::LEAVE_FUNCTION."\n");
                    // representations end

                    break;
                }

                case Opcodes::FUNC_CALL:
                {
                    $myfunc_call = $instruction->get_property("myfunc_call");
                    $myfunc_call->set_block_id($this->current_block_id);

                    if (is_null($myfunc_call->get_source_myfile()))
                        $myfunc_call->set_source_myfile($context->get_current_myfile());

                    if ($myfunc_call->is_type(MyFunction::TYPE_FUNC_METHOD))
                    {
                        $mybackdef = $myfunc_call->get_back_def();
                        $mybackdef->set_block_id($this->current_block_id);
                        $mybackdef->add_type(MyDefinition::TYPE_INSTANCE);
                        $mybackdef->set_source_myfile($context->get_current_myfile());

                        $id_object = $context->get_objects()->add_object();
                        $mybackdef->set_object_id($id_object);

                        $this->defs->adddef($mybackdef->get_name(), $mybackdef);
                        $this->defs->addgen($mybackdef->get_block_id(), $mybackdef);
                    }

                    $mysource = $context->inputs->get_source_byname(null, $myfunc_call, true, false, false);
                    if (!is_null($mysource))
                    {
                        if ($mysource->has_parameters())
                        {
                            $nbparams = 0;
                            while (true)
                            {
                                if (!$instruction->is_property_exist("argdef$nbparams"))
                                    break;

                                $defarg = $instruction->get_property("argdef$nbparams");

                                if ($mysource->is_parameter($nbparams + 1))
                                {
                                    $deffrom = $defarg->get_value_from_def();
                                    if (!is_null($deffrom))
                                    {
                                        $this->defs->adddef($deffrom->get_name(), $deffrom);
                                        $this->defs->addgen($deffrom->get_block_id(), $deffrom);
                                    }
                                }

                                $nbparams ++;
                            }
                        }
                    }

                    // representations start
                    $id_cfg = $this->current_func->getLine()."-".$this->current_func->getColumn()."-".$this->current_block_id;
                    $context->outputs->callgraph->add_edge($this->current_func, $myfunc_call);
                    $context->outputs->cfg->add_textofmyblock($id_cfg, Opcodes::FUNC_CALL." ".htmlentities($myfunc_call->get_name(), ENT_QUOTES, 'UTF-8')."\n");
                    // representations end

                    break;
                }

                case Opcodes::TEMPORARY:
                {
                    $mydef = $instruction->get_property("temporary");
                    $mydef->set_block_id($this->current_block_id);

                    if (is_null($mydef->get_source_myfile()))
                        $mydef->set_source_myfile($context->get_current_myfile());

                    // representations start
                    $id_cfg = $this->current_func->getLine()."-".$this->current_func->getColumn()."-".$this->current_block_id;
                    $context->outputs->cfg->add_textofmyblock($id_cfg, Opcodes::TEMPORARY."\n");
                    // representations end

                    break;
                }

                case Opcodes::DEFINITION:
                {
                    $mydef = $instruction->get_property("def");
                    $mydef->set_block_id($this->current_block_id);

                    if (is_null($mydef->get_source_myfile()))
                        $mydef->set_source_myfile($context->get_current_myfile());

                    $this->defs->adddef($mydef->get_name(), $mydef);
                    $this->defs->addgen($mydef->get_block_id(), $mydef);

                    if ($mydef->is_type(MyDefinition::TYPE_INSTANCE))
                    {
                        $myclass = $context->get_classes()->get_myclass($mydef->get_class_name());
                        if (is_null($myclass))
                        {
                            $myclass = new MyClass($mydef->getLine(),
                                                   $mydef->getColumn(),
                                                   $mydef->get_class_name());
                        }

                        /* mod */
                        $id_object = $context->get_objects()->add_object();
                        $mydef->set_object_id($id_object);
                        $context->get_objects()->add_myclass_to_object($id_object, $myclass);
                    }

                    // representations start
                    $id_cfg = $this->current_func->getLine()."-".$this->current_func->getColumn()."-".$this->current_block_id;
                    $context->outputs->cfg->add_textofmyblock($id_cfg, Opcodes::DEFINITION."\n");
                    // representations end

                    break;
                }
                }

                $index = $index + 1;
            }
        }
        while (isset($code[$index]) && $index <= $mycode->get_end());
    }
}
