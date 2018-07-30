<?php declare(strict_types=1);

namespace WyriHaximus\React\Cake\Http;

use Cake\Utility\Hash;
use WyriHaximus\React\Http\Middleware\Session as MiddlewareSession;

final class Session
{
    /**
     * @var MiddlewareSession
     */
    private $session;

    public function __construct(MiddlewareSession $session)
    {
        $this->session = $session;
    }

    /**
     * Sets the session handler instance to use for this session.
     * If a string is passed for the first argument, it will be treated as the
     * class name and the second argument will be passed as the first argument
     * in the constructor.
     *
     * If an instance of a SessionHandlerInterface is provided as the first argument,
     * the handler will be set to it.
     *
     * If no arguments are passed it will return the currently configured handler instance
     * or null if none exists.
     *
     * @param  string|\SessionHandlerInterface|null $class   The session handler to use
     * @param  array                                $options the options to pass to the SessionHandler constructor
     * @throws \InvalidArgumentException
     * @return \SessionHandlerInterface|null
     */
    public function engine($class = null, array $options = [])
    {
    }

    /**
     * Calls ini_set for each of the keys in `$options` and set them
     * to the respective value in the passed array.
     *
     * ### Example:
     *
     * ```
     * $session->options(['session.use_cookies' => 1]);
     * ```
     *
     * @param  array             $options Ini options to set.
     * @throws \RuntimeException if any directive could not be set
     */
    public function options(array $options)
    {
    }

    /**
     * Starts the Session.
     *
     * @throws \RuntimeException if the session was already started
     * @return bool              True if session was started
     */
    public function start()
    {
        return $this->session->begin();
    }

    /**
     * Determine if Session has already been started.
     *
     * @return bool True if session has been started.
     */
    public function started()
    {
        return $this->session->isActive();
    }

    /**
     * Returns true if given variable name is set in session.
     *
     * @param  string|null $name Variable name to check for
     * @return bool        True if variable is there
     */
    public function check($name = null)
    {
        if (!$this->started()) {
            $this->start();
        }

        return Hash::get($this->session->getContents(), $name) !== null;
    }

    /**
     * Returns given session variable, or all of them, if no parameters given.
     *
     * @param  string|null       $name The name of the session variable (or a path as sent to Hash.extract)
     * @return string|array|null The value of the session variable, null if session not available,
     *                                session not started, or provided name not found in the session.
     */
    public function read($name = null)
    {
        if (!$this->started()) {
            $this->start();
        }

        if ($name === null) {
            return $this->session->getContents();
        }

        return Hash::get($this->session->getContents(), $name);
    }

    /**
     * Reads and deletes a variable from session.
     *
     * @param  string $name The key to read and remove (or a path as sent to Hash.extract).
     * @return mixed  The value of the session variable, null if session not available,
     *                     session not started, or provided name not found in the session.
     */
    public function consume($name)
    {
        if (!$this->started()) {
            $this->start();
        }

        if (empty($name)) {
            return null;
        }
        $value = $this->read($name);
        if ($value !== null) {
            $this->session->setContents(Hash::remove($this->session->getContents(), $name));
        }

        return $value;
    }

    /**
     * Writes value to given session variable name.
     *
     * @param string|array $name  Name of variable
     * @param mixed        $value Value to write
     */
    public function write($name, $value = null)
    {
        if (!$this->started()) {
            $this->start();
        }

        $write = $name;
        if (!is_array($name)) {
            $write = [$name => $value];
        }

        $data = $this->session->getContents();
        foreach ($write as $key => $val) {
            $data = Hash::insert($data, $key, $val);
        }

        $this->session->setContents($data);
    }

    /**
     * Returns the session id.
     * Calling this method will not auto start the session. You might have to manually
     * assert a started session.
     *
     * Passing an id into it, you can also replace the session id if the session
     * has not already been started.
     * Note that depending on the session handler, not all characters are allowed
     * within the session id. For example, the file session handler only allows
     * characters in the range a-z A-Z 0-9 , (comma) and - (minus).
     *
     * @param  string|null $id Id to replace the current session id
     * @return string      Session id
     */
    public function id($id = null)
    {
        if (!$this->started()) {
            $this->start();
        }

        return $this->session->getId();
    }

    /**
     * Removes a variable from session.
     *
     * @param string $name Session variable to remove
     */
    public function delete($name)
    {
        if (!$this->started()) {
            $this->start();
        }

        if ($this->check($name)) {
            $this->session->setContents(Hash::remove($this->session->getContents(), $name));
        }
    }

    /**
     * Helper method to destroy invalid sessions.
     *
     */
    public function destroy()
    {
        if (!$this->started()) {
            $this->start();
        }

        $this->session->setContents([]);
    }

    /**
     * Clears the session.
     *
     * Optionally it also clears the session id and renews the session.
     *
     * @param bool $renew If session should be renewed, as well. Defaults to false.
     */
    public function clear($renew = false)
    {
        if (!$this->started()) {
            $this->start();
        }

        $this->session->setContents([]);
        if ($renew) {
            $this->renew();
        }
    }

    /**
     * Restarts this session.
     *
     */
    public function renew()
    {
        if (!$this->started()) {
            $this->start();
        }

        $this->session->regenerate();
    }
}
