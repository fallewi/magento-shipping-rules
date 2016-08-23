<?php
class Meanbee_Shippingrules_ShippingrulesController
    extends Mage_Adminhtml_Controller_Action
{
    public function indexAction()
    {
        $this->loadLayout()
             ->_setActiveMenu('catalog/meanbee_shippingrules')
             ->_setTitle('Manage Shipping Rules')
             ->renderLayout();
    }

    public function migrateAction()
    {
        /** @var Meanbee_Shippingrules_Helper_Migration $migrationHelper */
        $migrationHelper = Mage::helper('meanbee_shippingrules/migration');
        $migrate = $this->getRequest()->getParam('migrate', null);
        if ($migrate == 0) {
            $migrationHelper->ignoreMigration();
            $this->_redirect('*/*/');
        } else if ($migrate == 1) {
            $migrationHelper->migrateRules();
            $this->_addNotice('Migrated Rules have been disbled so they may be verified before going live.')
                 ->_redirect('*/*/');
        } else {
            $this->_redirect('*/*/');
        }
    }

    public function gridAction()
    {
        $this->loadLayout();
        $this->getResponse()->setBody($this->getLayout()->createBlock('meanbee_shippingrules/adminhtml_rules_grid')->toHtml());
    }

    public function newAction()
    {
        $this->_forward('edit');
    }

    public function editAction() {
        $id = $this->getRequest()->getParam('id', null);
        $model = Mage::getModel('meanbee_shippingrules/rule');

        if ($id) {
            $model->load((int) $id);
            if ($model->getId()) {
                $data = Mage::getSingleton('adminhtml/session')->getFormData(true);
                if ($data) {
                    $model->setData($data)->setId($id);
                }
            } else {
                $this->_addError('Shipping rule does not exist')
                     ->_redirect('*/*/');
            }
        }
        Mage::register('meanbee_shippingrules_data', $model);

        $this->loadLayout();

        $registers = new Meanbee_Shippingrules_Calculator_Registers;
        Mage::dispatchEvent(Meanbee_Shippingrules_Helper_Data::CALCULATOR_LOAD_EVENT, array(
            'registers' => $registers,
            'context' => 'collectScripts'
        ));
        $registers->getScriptRegister()->addScriptsToBlock($this->getLayout()->getBlock('head'));
        
        $this->getLayout()->getBlock('head')->setCanLoadExtJs(true);
        $this->_setTitle(($id ? 'Edit' : 'New') . ' Shipping Rule')
             ->_setActiveMenu('catalog/meanbee_shippingrules')
             ->renderLayout();
    }

    public function saveAction() {
        if ($data = $this->getRequest()->getPost()) {
            $rule = Mage::getModel('meanbee_shippingrules/rule');
            $id = $this->getRequest()->getParam('id');
            if ($id) {
                $rule->load($id);
            }

            $data['price_per_item'] = (int) isset($data['price_per_item']);
            $data['cost_per_item'] = (int) isset($data['cost_per_item']);
            $data['stop_rules_processing'] = (int) isset($data['stop_rules_processing']);
            $data['stop_all_rules_processing'] = (int) isset($data['stop_all_rules_processing']);

            $rule->addData($data);

            Mage::getSingleton('adminhtml/session')->setFormData($data);
            try {
                $rule->save();
                if (!$rule->getId()) {
                    Mage::throwException(Mage::helper('meanbee_shippingrules')->__('Error saving shipping rule'));
                }

                $this->_addSuccess(Mage::helper('meanbee_shippingrules')->__('Shipping rule was successfully saved.'));
                Mage::getSingleton('adminhtml/session')->setFormData(false);

                // The following line decides if it is a "save" or "save and continue"
                if ($this->getRequest()->getParam('back')) {
                    $this->_redirect('*/*/edit', array('id' => $rule->getId()));
                } else {
                    $this->_redirect('*/*/');
                }
            } catch (Exception $error) {
                $this->_addError($error->getMessage());
                if ($rule && $rule->getId()) {
                    $this->_redirect('*/*/edit', array('id' => $rule->getId()));
                } else {
                    $this->_redirect('*/*/');
                }
            }
            return;
        }
        $this->_addError(Mage::helper('meanbee_shippingrules')->__('No data found to save'))
             ->_redirect('*/*/');
    }

    public function deleteAction()
    {
        if ($id = $this->getRequest()->getParam('id')) {
            try {
                Mage::getModel('meanbee_shippingrules/rule')->setId($id)->delete();
                $this->_addSuccess('The shipping rule has been deleted.')
                     ->_redirect('*/*/');
                return;
            } catch (Exception $e) {
                $this->_addError($e->getMessage())
                     ->_redirect('*/*/edit', array('id' => $id));
                return;
            }
        }
        $this->_addError('Unable to find the shipping rule to delete.')
             ->_redirect('*/*/');
    }

    public function duplicateAction()
    {
        if ($id = $this->getRequest()->getParam('id')) {
            $model = Mage::getModel('meanbee_shippingrules/rule')->load($id);
            if ($model->getId()) {
                $new_rule = $model->duplicate();
                $this->_addSuccess('Your rule has been successfully duplicated. You can edit your newly created rule below.')
                     ->_redirect('*/*/edit', array('id' => $new_rule->getId()));
                return;
            } else {
                $this->_addError("Couldn't load the rule to be duplicated");
            }
        }

        $this->_redirect('*/*');
    }

    public function massEnableAction()
    {
        $this->_massAction('enable', 'enabled', 'enabling', function ($rule) {
            $rule->setIsActive(true)
                 ->save();
        });
    }

    public function massDisableAction()
    {
        $this->_massAction('disable', 'disabled', 'disabling', function ($rule) {
            $rule->setIsActive(false)
                 ->save();
        });
    }

    public function massDeleteAction()
    {
        $this->_massAction('delete', 'deleted', 'deleting', function ($rule) {
            $rule->delete();
        });
    }

    public function ajaxAction()
    {
        $request = $this->getRequest();
        
        $module = $request->getModuleName();
        $controller = $request->getControllerName();
        $action = $request->getActionName();
        $parameterStr = rtrim(preg_replace("~^/$module/$controller/$action/~", '', $request->getPathInfo()), '/');
        $parameters = explode('/', $parameterStr);
        $arguments = array_slice($parameters, 3);

        $registers = new Meanbee_Shippingrules_Calculator_Registers;
        if (($register = $registers->getRegister($parameters[0]))) {
            if (($registerItem = $register->get($parameters[1]))) {
                if (in_array('ajax'.ucfirst($parameters[2]), get_class_methods($registerItem))) {
                    $result = call_user_func(array($registerItem, 'ajax'.ucfirst($parameters[2])), ...$arguments);
                } else {
                    $result = array('error' => true, 'type' => 400, 'message' => "Ajax method, `{$parameters[2]}` does not exist.");
                }
            } else {
                $result = array('error' => true, 'type' => 400, 'message' => "Register item, `{$parameters[1]}` does not exist.");
            }
        } else {
            $result = array('error' => true, 'type' => 400, 'message' => "Register, `{$parameters[0]}` does not exist.");
        }
        if ($result['error']) {
            $this->getResponse()->setHttpResponseCode($result['type'] ?: 500);
        }
        $this->getResponse()->setHeader('Content-type', 'application/json');
        $this->getResponse()->setBody(json_encode($result));
    }
    
    protected function _massAction($infinitive, $past, $present, $callback)
    {
        if ($this->getRequest()->isPost()) {
            $rule_ids = $this->getRequest()->getParam('rule_id');
            $count = count($rule_ids);
            if ($count > 0) {
                $rules = Mage::getModel('meanbee_shippingrules/rule')->getCollection()
                                                                     ->addFieldToFilter('rule_id', array('in' => $rule_ids));
                foreach ($rules as $rule) {
                    try {
                        $callback($rule);
                    } catch (Exception $err) {
                        $this->_addError('Unable to ' . $infinitive . 'rule' . ($count > 1 ? 's' : ''));
                    }
                }
                $this->_addSuccess('Successfully ' . $past . ' shipping rule' . ($count > 1 ? 's' : ''));
            } else {
                $this->_addNotice('No rules were selected for ' . $present);
            }
        }
        $this->_redirect('*/*');
    }

    protected function _setTitle($title)
    {
        $this->getLayout()->getBlock('head')->setTitle(Mage::helper('meanbee_shippingrules')->__($title));
        return $this;
    }

    protected function _addSuccess($message)
    {
        Mage::getSingleton('adminhtml/session')->addSuccess(Mage::helper('meanbee_shippingrules')->__($message));
        return $this;
    }

    protected function _addError($message)
    {
        Mage::getSingleton('adminhtml/session')->addError(Mage::helper('meanbee_shippingrules')->__($message));
        return $this;
    }

    protected function _addNotice($message)
    {
        Mage::getSingleton('adminhtml/session')->addNotice(Mage::helper('meanbee_shippingrules')->__($message));
        return $this;
    }
}
